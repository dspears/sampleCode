

// Vue Components to render the sidebar menu

Vue.component('side-menu-item', {
  props: ['item', 'id', 'selected', 'locked'],
  template: `
    <li :class="{selected: selected===id}" :data-id="id" @click.prevent="$emit('item',$event)">
      <a href="#" class="item" >
        <i :class="'fa '+item.icon"></i>{{ item.name }}
      </a>
      <span>{{ item.number }}</span>
      <span class="actionicons" v-if="!locked">
        <a class="action deleteBlogFolder"  href="#"  @click.prevent.stop="$emit('delete',id)" v-tooltip="'Delete blog from Feed'">
          <span class="glyphicon glyphicon-trash"></span>
        </a>
      </span>
    </li>
  `,
});


Vue.component('side-menu-category', {
  props: ['category', 'items', 'selected'],
  template: `
    <li>
      <div :class="{selected: selected===categoryId()}" :data-id="categoryId()" @click.prevent="$emit('category',$event)">
        <i :class="classNames()" @click.stop="toggle"></i><a href="#" class="category">{{ category.name }}</a>
        <span class="actionicons" v-if="!category.locked">
          <a class="action deleteBlogFolder" href="#" @click.prevent="$emit('delete',categoryId())" v-tooltip="'Delete Feed'">
            <span class="glyphicon glyphicon-trash"></span>
          </a>
        </span>
      </div>
      <ul class="items" v-if="isOpen">
        <side-menu-item v-for="item in items" :item="item" :key="item.id" :id="itemId(item)" :selected="selected" :locked="category.locked" 
                        @item="$emit('item',$event)" @delete="$emit('delete',$event)" />
      </ul>
    </li>
  `,
  methods: {
    toggle() {
      this.isOpen = !this.isOpen;
    },
    classNames() {
      let classes = 'expand fa fa-angle-right';
      classes += this.isOpen ? ' open' : '';
      classes += this.selected === this.category.id ? ' selected' : '';
      return classes;
    },
    categoryId() {
      return `feed::${this.category.name}::${this.category.id}`;
    },
    itemId(item) {
      return `feed::${this.category.name}::${this.category.id}::${item.name}::${item.id}`;
    },
  },
  data: function() { 
    return {
      isOpen: false,
    }
  },
});


Vue.component('side-menu-folder', {
  props: ['folder', 'selected'],
  template: `
    <li :class="{selected: selected===folderId()}" :data-id="folderId()" @click.prevent="$emit('folder',$event)">
      <a href="#">{{ folder.name }}</a>
      <span class="actionicons">
        <a class="action deleteFolder" href="#" @click.prevent="$emit('delete',folderId())" v-tooltip="'Delete Folder'">
          <span class="glyphicon glyphicon-trash"></span>
        </a>
      </span>
    </li>
  `,
  methods: {
    folderId() {
      return `folder::${this.folder.name}::${this.folder.id}`;
    }
  }
});


Vue.component('side-menu-toplevel-item', {
  props: ['item', 'selected'],
  template: `
    <ul>
      <li :class="itemClass()">
        <a :data-id="item.id" href="#" @click.prevent="$emit('item',$event)">{{ item.name }}</a>
      </li>
    </ul>
  `,
  methods: {
    itemClass() {
      return {toplevel: true, selected: this.selected===this.item.id};
    },
  }
});

Vue.component('how-to-favorite-posts', {
  template: `
    <p>
    <span class="glyphicon glyphicon-heart-empty"></span>
    Save posts you like as favorites.
    </p>
  `,
});

Vue.component('how-to-follow-blogs', {
  template: `
    <p>
    <span class="glyphicon glyphicon-plus-sign"></span>
    Add blogs to your "followed" list.
    </p>
  `,
});

Vue.component('how-to-use-feeds', {
  template: `
    <p>
    <span class="material-icons">create_new_folder</span>
    Group related blogs into "feeds".
    </p>
  `,
});

Vue.component('how-to-use-folders', {
  template: `
    <div>
      <p>
      <span class="glyphicon glyphicon-folder-close"></span>
      Organize posts into folders.
      </p>
    </div>
  `,
});

Vue.component('side-menu-signpost', {
  template: `
    <div class="side-menu-signpost">
      <h2>Welcome to RadRSS Reader!</h2>
      <p>You can save favorite posts and blogs, then easily access them here.</p>
      <div class="side-arrow"></div>
    </div>
  `,
});

Vue.component('side-menu-welcome', {
  template: `
    <div class="side-menu-welcome">
      <h3>Welcome!</h3>
      <p>You can always access your personalized content from here.</p>
      <p>You currently don't have any saved posts or blogs.  You can start saving content from any post:</p>
      <how-to-favorite-posts />
      <how-to-follow-blogs />
      <how-to-use-feeds />
      <how-to-use-folders />
    </div>
  `,
});


Vue.component('side-panel', {
  props: ['feeds', 'folders', 'selected', 'followed', 'favorites', 'loading'],
  template: `
    <div class="sideMenu" :class="show">
      <div id="sideMenuTab" @click="showHide">
        <i class="fa fa-bars"></i>
        <side-menu-signpost v-if="isNewUser()" />
      </div>
      <side-menu-welcome v-if="isNewUser()" />
      <div v-else class="sideMenuContent">
        <br />
        <side-menu-toplevel-item :item="{name: 'All Blogs', id: 'allblogs'}" :selected="selected" @item="setItem" />

        <side-menu-toplevel-item  v-if="favorites.length" :item="{name: 'Favorite Posts', id: 'favorites'}" :selected="selected" @item="setItem" />
        <div v-else class="no-content">
          <p>You don't have any favorites yet.</p>
          <how-to-favorite-posts />
        </div>
       
        <ul v-if="followed.length" class="categories">
          <side-menu-category :key="'followed'" :category="{name:'Followed Blogs',id:'0',locked:true}" 
                :items="followed" :selected="selected" @category="setCategory" @item="setItem" />
        </ul>
        <div v-else class="no-content">
          <p>You're not following any blogs yet.</p>
          <how-to-follow-blogs />
        </div>
        

        <div v-if="feeds.length">
          <h2>Feeds</h2>
          <ul class="categories">
            <side-menu-category v-for="feed in feeds" :key="feed.id" :category="feed" :items="feed.children" :selected="selected" 
                  @category="setCategory" @item="setItem" @delete="deleteItem" />
          </ul>
        </div>
        <div v-else class="no-content">
          <p>You don't have any feeds yet.</p>
          <how-to-use-feeds />
        </div>

        <div v-if="folders.length">
          <h2>Folders</h2>
          <ul v-if="folders.length" class="folders">
            <side-menu-folder v-for="folder in folders" :key="folder.id" :folder="folder"
                :selected="selected" @folder="setFolder" @delete="deleteItem" />
          </ul>
        </div>
        <div v-else class="no-content">
          <p>You don't have any folders yet.</p>
          <how-to-use-folders />
        </div>

      </div>
    </div>
  `,
  methods: {
    showHide() {
      this.show = this.show === '' ? 'show' : '';
    },
    setCategory($event) {
      let id = $event.currentTarget.getAttribute('data-id');
      this.$emit('select', id);
    },
    setItem($event) {
      let id = $event.currentTarget.getAttribute('data-id');
      this.$emit('select', id);
    },
    setFolder($event) {
      let id = $event.currentTarget.getAttribute('data-id');
      this.$emit('select', id);
    },
    deleteItem($event) {
      this.$emit('delete', $event);
    },
    isNewUser() {
      return !this.loading && this.feeds.length === 0 && this.folders.length === 0 && this.followed.length === 0 && this.favorites.length === 0;
    },
  },
  data: function() { 
    return {
      show: '',
    }
  },
});

