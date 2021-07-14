<?php
namespace App\Classes;
use Illuminate\Http\Request;
use App\Pfolder;
use App\Bfolder;
use App\Elastic;
use App\Providers\ElasticServiceProvider;
use App\Post;
use App\Blog;
use App\Category;
use DB;
use \Log;

/*
 * This class contains the code to support Blog and Post queries for both MySQL and ElasticSearch.
 *
 */

class HomePageHelper {
    private $order = "published";
    private $pageSize = 12;
    private $only_show_posts_with_images = false;
    
    public function __construct() {
      $this->only_show_posts_with_images = getenv('ONLY_SHOW_POSTS_WITH_IMAGES') == 'yes';
    }

    /**
     * Get all the query parameters that control what content is being pulled from the database
     *
     * @param Request $request
     * @return array
     */
    public function getQueryParams(Request $request) {
      $params = $request->only(['page','order','rangeMin','category','search','blogid','favoritemode','lang','foldermode','folderid','layout']);
      // Need to pass the search string through urldecode so that spaces don't show up as '%20' for example.
      $params['search'] = urldecode($params['search']);
      $params['signedIn'] = $request->user() ? true : false;
      // if lang not set, default to English
      $params['lang'] = is_null($params['lang']) ? 'en' : $params['lang'];
      $params['order'] = is_null($params['order']) ? $this->order : $params['order'];
      $params['rangeMin'] = !isset($params['rangeMin']) ? '' : $params['rangeMin'];
      $layout = $request->cookie('rad_layout');
      $session_layout = empty($layout) ? 'Tiles' : $layout;
      $params['layout'] = is_null($params['layout']) ? $session_layout : $params['layout'];
      return $params;
    }

    protected function isCategoryQuery($query) {
      return ($query['category'] != "Everything" && $query['category'] != '');
    }

    protected function isExploreModeQuery($request, $query) {
      // dd($query);
      if (isset($query['blogid'])) {
        return false;
      } else if (!empty($query['search'])) {
        return false;
      } else if ($request->user() && 
        ($query['favoritemode'] == "posts" || $query['favoritemode'] == "blogs" || $query['foldermode'] == 'b' || $query['foldermode'] == 'p')) {
        return false;
      } else {
        return true;
      }
    }

    /**
     * Constructs a query of the posts table based on the query parameters that are passed in.
     *
     * @param Request $request
     * @param $query
     * @return mixed
     * 
     * Notes: 
     * Too slow:  
     * select * from posts WHERE blog_id in (select blog_category.blog_id FROM blog_category inner join categories on blog_category.category_id=categories.id WHERE categories.name='Food') order by posts.published limit 12
     */
    public function buildPostQuery(Request $request, &$query) {
      $debug = false;
      $exploreMode = $this->isExploreModeQuery($request, $query);
      // If there's a search phrase (from the search box input), use Elasticsearch, else use MySQL
      if (!empty($query['search'])) {
          // Use Elasticsearch:
          $posts = $this->doElasticPostSearch($request,$query);
      } else {
          // Use MySQL
          // Common part of sql query:
          if ($query['favoritemode'] == "posts" && $request->user()) {
              // Favorite Posts:
              // use the relation users to posts and chain the query
              $user = $request->user();
              $dbQuery = Post::where('blocked', '0');
              // Note: This is NOT Needed and it actually causes the query to run for a long time:  $dbQuery = $this->isCategoryQuery($query) ? Post::IndexRaw('FORCE INDEX (published_index)')->where('blocked','0') : Post::where('blocked', '0');
              $dbQuery->join('post_user', 'posts.id', '=', 'post_user.post_id')->where('post_user.user_id',$user->id);
          } else if ($query['favoritemode'] == "blogs" && $request->user()) {
              // Followed Blogs:
              $dbQuery = Post::IndexRaw('FORCE INDEX (published_index)')->where('blocked','0');
              // The following join was quite slow (~2.5 to 3 seconds)
              //   $dbQuery = $dbQuery->join('blog_user', 'posts.blog_id', '=', 'blog_user.blog_id')->where('blog_user.user_id', '=', $request->user()->id);
              //
              // Might still be able to make this slightly faster by just doing the query for the list of blog id's on blog_user table
              //       i.e.  SELECT blog_id FROM blog_user WHERE user_id='128'
              $blog_ids = $request->user()->blogs()->pluck('blogs.id');
              $dbQuery = $dbQuery->whereIn('posts.blog_id', $blog_ids);
              // Note:  Tried to Replace with the following, but it was slower:  
              // $user = $request->user();
              // $user_id = $user->id;
              // $dbQuery->whereIn('posts.blog_id',function($q) use($user_id) {
              //   $q->select('blog_id')->from('blog_user')->where('blog_user.user_id', $user_id);
              // });
          } else {
              $dbQuery = $this->isCategoryQuery($query) ? Post::IndexRaw('FORCE INDEX (published_index)')->where('blocked','0') : Post::where('blocked', '0');
          }
          $dbQuery->select('posts.*');
          if ($query['foldermode'] == 'p' && $request->user()) {
              // Specific Post Folder:
              // We are restricting this query to just posts that are in the specified pfolder.
              $pfolder = Pfolder::where('id', $query['folderid'])->first();
              if ($pfolder) {
                  $dbQuery = $pfolder->posts();
                  // Override header title
                  $query['headerTitle'] = "Folder " . $pfolder->name;
                  $query['folderName'] = $pfolder->name;
              }
          } else if ($query['foldermode'] == 'b' && $request->user()) {
              // Specific Blog Folder
              // Need a query that pulls posts just from the blogs in this bfolder
              $bfolder = Bfolder::where('id', $query['folderid'])->first();
              $blog_ids = $bfolder->blogs()->pluck('blogs.id');
              $dbQuery->whereIn('posts.blog_id',$blog_ids);
              // Override header title
              $query['headerTitle'] = "Feed " . $bfolder->name;
              $query['folderName'] = $bfolder->name;
          }
          // Continue building the query; Common part of the query for both conditions above
          $rangeMin = isset($query['rangeMin']) ? $query['rangeMin'] : false;
          $order = $query['order'];
          if ($exploreMode) {
            $dbQuery = $dbQuery->where('random_rank','>',1000);
          }
          if ($rangeMin && $order == 'published') {
            $dbQuery = $dbQuery->where('published','<',$rangeMin)->take($this->pageSize);
          } else {
            $dbQuery = $dbQuery->skip($this->pageSize * $query['page'])->take($this->pageSize);
          }
          // If category is "Everything" we don't need the "where" for category
          /**
           * For category queries we need to end up with something like this:
           * select * from `posts` where id in
           * (SELECT posts.id FROM posts inner join `blog_category` on `posts`.`blog_id` = `blog_category`.`blog_id` inner join `categories` on `blog_category`.`category_id` = `categories`.`id` where `blocked` = '0' and `categories`.`name` = 'Beauty' and `lang` like 'en%')
           * order by `published` desc limit 12 offset 0
           */
          if ($this->isCategoryQuery($query)) {

              // Conditional parts of query:

              /*
               * Note: For some reason, the following query is extremely slow.  Queries were taking as much as 14 seconds.
               * I was able to re-structure the query using a subquery and whereIn and get the performance of the same
               * query to down around 5ms!
               *
               * $dbQuery = $dbQuery->join('blog_category', 'posts.blog_id', '=', 'blog_category.blog_id')->join('categories','blog_category.category_id','=','categories.id')->where('categories.name',$query['category']);
               *
               * Restructure as a whereIn subquery:
               * select `posts`.* from `posts` where `blocked` = '0' and `id` in (select `posts`.`id` from `posts` inner join `blog_category` on `posts`.`blog_id` = `blog_category`.`blog_id` inner join `categories` on `blog_category`.`category_id` = `categories`.`id` where `categories`.`name` = 'Food') and `lang` like 'en%'  order by posts.`published` desc limit 12 offset 0
               */
              $category = $query['category'];
              $categoryModel = Category::where('name', $category)->first();
              if ($categoryModel) {
                $blogIdsInCategory = DB::table('blog_category')->where('category_id',$categoryModel->id)->pluck('blog_id');
                $dbQuery->whereIn('posts.blog_id', $blogIdsInCategory);
              } else {
                $dbQuery->whereIn('posts.id',function($q) use($category) {
                  $q->select('posts.id')->from('posts')->join('blog_category', 'posts.blog_id', '=', 'blog_category.blog_id')->join('categories','blog_category.category_id','=','categories.id')->where('categories.name',$category);
                });
              }

          }
          if ($query['blogid'] != "") $dbQuery = $dbQuery->where('blog_id', $query['blogid']);
          if ($query['lang'] != "") $dbQuery = $dbQuery->where('lang', 'like', $query['lang'] . '%');
          if ($this->only_show_posts_with_images == 'yes' || $exploreMode) $dbQuery->where('image','<>','');
          if ($query['order'] != "") {
              $ordering = explode('|',$query['order']);
              $orderField = $ordering[0];
              // Default order direction to desc, but allow user to specify (e.g. published|asc).
              $orderDirection = empty($ordering[1]) ? 'desc' : $ordering[1];
              $dbQuery = $dbQuery->orderby($orderField, $orderDirection);
          } else {
              $dbQuery = $dbQuery->orderby('published', 'asc');
          }
          if ($debug) {
            echo "SQL:" . $dbQuery->toSql();
            echo "<br><br>\n\n";
            dd($dbQuery);
          }
          $posts = $dbQuery->get();
          // TODO: Can this be taken care of via a join?:
          foreach ($posts as &$post) {
            $post->blog_name = $post->blog->name;
            $post->blog_url = $post->blog->url;
          }
          if ($debug) {
            dd($posts);
          }
      }
      if (count($posts) > 0) {
        $query['blogName'] = $posts[0]->blog_name;
      }
      return $posts;
    }

    /**
     * Do a search of posts using Elasticsearch.
     * This function gets the query parameters setup, then calls doElasticPostQuery to execute the query.
     *
     * @param Request $request
     * @param $query
     * @return \Elasticquent\ElasticquentResultCollection
     */
    private function doElasticPostSearch(Request $request, &$query) {
        $post_id_list = [];
        $blog_id_list = [];
        // Setup data for user-specific queries (search within user's favorites, followed blogs, pfolders)
        if ($query['favoritemode']=="posts" && $request->user()) {
            // Search within a user's favorite posts.
            // use the relation users to posts to get the set of post id's the user has favorited
            $post_id_list = $request->user()->posts()->pluck('posts.id');
        } else if ($query['favoritemode']=="blogs" && $request->user()) {
            // Search within a user's followed blogs.
            // use the relation users to blogs to get the set of blog id's the user is following
            $blog_id_list = $request->user()->blogs()->pluck('blogs.id');
        }
        if ($query['foldermode']=='p' && $request->user()) {
            // Search within a user's pfolder.
            $pfolder = Pfolder::where('id',$query['folderid'])->first();
            if ($pfolder) {
                // Override header title
                $query['headerTitle'] = "Folder ".$pfolder->name;
                $query['folderName'] = $pfolder->name;
                // use relation pfolders to posts to get set of posts id's in the folder
                $post_id_list = $pfolder->posts()->pluck('posts.id');
            }
        } else if ($query['foldermode']=='b' && $request->user()) {
            $bfolder = Bfolder::where('id',$query['folderid'])->first();
            if ($bfolder) {
                // Override header title
                $query['headerTitle'] = "Feed ".$bfolder->name;
                $query['folderName'] = $bfolder->name;
                // use relation bfolders to blogs to get set of blog id's in the folder
                $blog_id_list = $bfolder->blogs()->pluck('blogs.id');
            }
        }
        $size = $this->pageSize * 2; // Double the page size for Elasticsearch queries.
        $from = $size * $query['page'];
        // We used to call this function: $posts = $this->doElasticPostQuery($query, $from, $size, $post_id_list, $blog_id_list);
        $posts = $this->doDateBoostedElasticPostQuery($query, $from, $size, $post_id_list, $blog_id_list);
        // dd($posts);
        return $posts;
    }

  
    /**
     * This function does a search query of posts using Elasticsearch, but with posts within the
     * last year boosted to have highest scores, and those posts 1 to 3 years ago to be boosted
     * over older posts as well.
     * 
     * Some valuable links regarding function_score queries:  
     * https://discuss.elastic.co/t/how-to-prioritize-more-recent-content/134100/2
     * https://www.elastic.co/blog/found-function-scoring
     * 
     * 
     * Can get more fancy in the future:  
     * https://www.elastic.co/guide/en/elasticsearch/reference/2.4/query-dsl-function-score-query.html#score-functions
     * https://www.elastic.co/guide/en/elasticsearch/reference/2.4/query-dsl-function-score-query.html
     * 
     * 
     * @param $query - search string
     * @param $from - results starting from this offset
     * @param $size - number of results to return
     * @param $post_id_list - search within this list of posts 
     * @param $blog_id_list - search within this list of blogs
     * @return \Elasticquent\ElasticquentResultCollection
     */
    private function doDateBoostedElasticPostQuery($query,$from,$size,$post_id_list,$blog_id_list) {
      $esQuery = [
        'multi_match' => [
          'query' => $query['search'],
          'fuzziness' => 'AUTO',
          'prefix_length' => 2,
          'fields' => ['title^3', 'plain_text', 'blog_name'],
        ],
      ];

      $functions = [
        ['filter' => ['range' => ['published' => ['gte'=>'now-1y', 'lte'=>'now']]], 'weight' => 10],
        ['filter' => ['range' => ['published' => ['gte'=>'now-4y', 'lt'=>'now-1y']]], 'weight' => 2],
      ];

      if ($this->only_show_posts_with_images) {
        // If it has an image, we boost it with weight of 12.
        $functions[] = ['filter' => ['regexp' => ['image' => '..*']], 'weight'=> 12];
      }

      $function_score = [
        'query' => $esQuery,
        'functions' => $functions,
        'boost_mode' => "multiply",
      ];

      $outerQuery = [
        'bool' => [
          'must' => [
            'function_score' => $function_score,
          ]
        ]
      ];

      $filter =  [
        'bool' => [
          'must' => [
            ['term' => ['blocked' => '0']],
            ['wildcard' => ['lang' => $query['lang'].'*']],
            // Restrict qurey to posts type
            ["type" => [ "value" => "posts" ]],
          ]
        ]
      ];

      // Using this approach we can require that all posts in the results have an image.
      // For now, we're going with the filter function above that boosts posts that have an image.
      // This let's the non-image posts still come through buy with much lower scores.
      //
      // if ($this->only_show_posts_with_images) {
      //   $filter['bool']['must'][] = ['regexp' => ['image' => '..*']];
      // }

      if ($query['blogid'] != "") {
        $filter['bool']['must'][] = ['term' => ['blog_id'=>$query['blogid']]];
      }
      if ($query['category'] != "" && $query['category']!='Everything') {
        $filter['bool']['must'][] = ['term' => ['category'=>strtolower($query['category'])]];
      }
      if (!empty($post_id_list)) {
        $filter['bool']['must'][] = ['terms' => ['id'=>$post_id_list]];
      }
      if (!empty($blog_id_list)) {
        $filter['bool']['must'][] = ['terms' => ['blog_id'=>$blog_id_list]];
      }

      $parameters = [
        'body' => [
          'from' => $from,
          'size' => $size,
          'query' => $outerQuery,
          'filter' => $filter,
        ],
      ];

      $posts = Post::complexSearch($parameters);

      return $posts;
    }

    /**
     * Constructs a query of the blogs table based on the query parameters that are passed in.
     *y
     * @param Request $request
     * @param $query
     * @return mixed
     */
    public function buildBlogQuery(Request $request, &$query) {
      // If there's a search phrase (from the search box input), use Elasticsearch, else use MySQL
      if (!empty($query['search'])) {
        // Use Elasticsearch for the query:
        $blogs = $this->doElasticBlogSearch($request,$query);
      } else {
        // Use MySQL for the query
        // Common part of query:
        if ($query['favoritemode'] == "blogs" && $request->user()) {
          // use the relation users to blogs and chain the query
          $dbQuery = $request->user()->blogs()->where('blocked', '0');
        } else {
          $dbQuery = \App\Blog::where('blocked', '0');
        }

        $dbQuery->select('blogs.*');

        if ($query['foldermode']=='b' && $request->user()) {
          // We are restricting the query to just blogs that are in the specified bfolder
          $bfolder = Bfolder::where('id', $query['folderid'])->first();
          if ($bfolder) {
              $dbQuery = $bfolder->blogs();
              // Override header title
              $query['headerTitle'] = "Feed ".$bfolder->name;
              $query['folderName'] = $bfolder->name;
          }
        }

        // Build up the query incrementally to avoid complex logic with many if-else blocks
        $dbQuery = $dbQuery->skip($this->pageSize * $query['page'])->take($this->pageSize)->orderby('rank');

        // Conditional parts of query:
        //
        // If category is "Everything" we don't need the "where" for category
        // The reason being that in the database there's no posts or blogs that will
        // have "everything"  as their category.
        if ($query['category'] != "Everything" && $query['category'] != '') {
          if ($query['category'] != "") {
              // Old method where each blog had only a single category:
              // $dbQuery = $dbQuery->where('category', $query['category']);
              // New method:
              $dbQuery = $dbQuery->join('blog_category', 'blogs.id', '=', 'blog_category.blog_id')->join('categories','blog_category.category_id','=','categories.id')->where('categories.name',$query['category']);
          }
        }

        if ($query['search'] != "") $dbQuery = $dbQuery->where('name','like',"%{$query['search']}%");
        if ($query['lang'] != "") $dbQuery = $dbQuery->where('lang', 'like', $query['lang'] . '%');
        // For debugging:
        $debug = false;
        if ($debug) {
          echo "Mem1 ".memory_get_usage()."<br>";
          echo "SQL: ".$dbQuery->toSql();
          echo "<br>\n";
        }
        $blogs = $dbQuery->get();
        if ($debug) {
          echo "Query returned. Mem2: ".memory_get_usage()."<br>";
        }
      }
      return $blogs;
    }

    /**
     * Do a search of posts using Elasticsearch.
     * This function gets the query parameters setup, then calls doElasticPostQuery to execute the query.
     *
     * @param Request $request
     * @param $query
     * @return \Elasticquent\ElasticquentResultCollection
     */
    private function doElasticBlogSearch(Request $request, &$query) {
        $blog_id_list = [];
        // Setup data for user-specific queries (search within user's favorites, followed blogs, pfolders)
        if ($query['favoritemode']=="blogs" && $request->user()) {
            // Search within a user's followed blogs.
            // use the relation users to blogs to get the set of blog id's the user is following
            $blog_id_list = $request->user()->blogs()->pluck('blogs.id');
        }
        if ($query['foldermode']=='b' && $request->user()) {
            // Search within a user's bfolder.
            $bfolder = Bfolder::where('id',$query['folderid'])->first();
            if ($bfolder) {
                // Override header title
                $query['headerTitle'] = "Feed ".$bfolder->name;
                $query['folderName'] = $bfolder->name;
                // use relation pfolders to posts to get set of posts id's in the folder
                $blog_id_list = $bfolder->blogs()->pluck('blogs.id');
            }
        }
        $size = $this->pageSize;
        $from = $size * $query['page'];
        $blogs = $this->doElasticBlogQuery($query, $from, $size, $blog_id_list);
        return $blogs;
    }

    /**
     * This function does a search query of Blogs via Elasticsearch.
     *
     * @param $query
     * @param $from
     * @param $size
     * @return \Elasticquent\ElasticquentResultCollection
     */
    private function doElasticBlogQuery($query,$from,$size,$blog_id_list) {
        $esQuery = [
            //'multi_match' => [
            'query_string' => [
                'query' => '*'.$query['search'].'*',
                'fuzziness' => 'AUTO',
                //'prefix_length' => 2,
                'fields' => ['name^3', 'category'],
            ],
        ];

        $filter =  [
            'bool' => [
                'must' => [
                    ['term' => ['blocked' => '0']],
                    ['wildcard' => ['lang' => $query['lang'].'*']],
                    // Restrict query to blogs type
                    ["type" => [ "value" => "blogs" ]],
                ]
            ]
        ];

        if ($query['category'] != "" && $query['category']!='Everything') {
            // Note that category in the Elasticsearch type mapping is an array.
            $filter['bool']['must'][] = ['term' => ['category'=>strtolower($query['category'])]];
        }
        if (!empty($blog_id_list)) {
            $filter['bool']['must'][] = ['terms' => ['id'=>$blog_id_list]];
        }

        $parameters = [
            'body' => [
                "from" => $from,
                "size" => $size,
                'query' => $esQuery,
                'filter' => $filter,
            ],
        ];

        $blogs = Blog::complexSearch($parameters);

        return $blogs;
    }

} 
