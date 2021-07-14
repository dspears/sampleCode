
const projectService = require('../services/project_service');

class ProjectController {
  constructor(projectService) {
    this.projectService = projectService;
    this.create = this.create.bind(this);
    this.readById = this.readById.bind(this);
    this.readAll = this.readAll.bind(this); 
    this.update = this.update.bind(this);
    this.delete = this.delete.bind(this);
    this.getCommits = this.getCommits.bind(this);
    this.getCommitsWithStats = this.getCommitsWithStats.bind(this);
    this.getTree = this.getTree.bind(this);
    this.getFile = this.getFile.bind(this);
    this.search = this.search.bind(this);
    this.addUser = this.addUser.bind(this);
  }

  async create(req, res, next) {
    try {
      const proj = await this.projectService.create(req.user._id, req.body);
      res.json({status: 'success', 'project': proj});
    } catch(err) {
      next(err);
    }
  }

  async readById(req, res, next) {
    try {
      const p_id = req.params.p_id;
      const projects = await this.projectService.readById(req.user._id, p_id);
      res.json({status: 'success', 'projects': projects});
    } catch(err) {
      next(err);
    }
  }

  async getCommits(req, res, next) {
    try {
      const p_id = req.params.p_id;
      const commits = await this.projectService.getCommits(req.user._id, p_id);
      res.json({status: 'success', p_id, commits});
    } catch(err) {
      next(err);
    }
  }

  async getCommitsWithStats(req, res, next) {
    try {
      const p_id = req.params.p_id;
      const commits = await this.projectService.getCommits(req.user._id, p_id, true);
      res.json({status: 'success', p_id, commits});
    } catch(err) {
      next(err);
    }
  }

  async getTree(req, res, next) {
    try {
      const p_id = req.params.p_id;
      const tree = await this.projectService.getTree(req.user._id, p_id);
      res.json({status: 'success', p_id, tree});
    } catch(err) {
      next(err);
    }
  }

  async getFile(req, res, next) {
    try {
      const p_id = req.params.p_id;
      const path = req.params.path;
      this.projectService.streamFile(req.user._id, p_id, path, res);
    } catch(err) {
      next(err);
    }
  }

  async readAll(req, res, next) {
    try {
      const projects = await this.projectService.readAll(req.user._id,);
      res.json({status: 'success', 'projects': projects});
    } catch(err) {
      next(err);
    }
  }

  async search(req, res, next) {
    try {
      const p_id = req.params.p_id;
      const s = req.params.s;
      const results = await this.projectService.search(req.user._id, p_id, s);
      const jsonString = `{"status": "success", "results": [${results.slice(0,-2)}]}`;
      res.end(jsonString);
    } catch(err) {
      next(err);
    }
  }

  async update(req, res, next) {
    try {
      const p_id = req.params.p_id;
      const proj = await this.projectService.update(req.user._id, p_id, req.body);
      res.json({status: 'success', 'project': proj});
    } catch(err) {
      next(err);
    }
  }

  async addUser(req, res, next) {
    try {
      const p_id = req.params.p_id;
      const email = req.body.email;
      console.log("Adding user ",email, p_id);
      const proj = await this.projectService.addUser(req.user._id, p_id, email);
      res.json({status: 'success', 'project': proj});
    } catch(err) {
      next(err);
    }
  }

  async delete(req, res, next) {
    try {
      const p_id = req.params.p_id;
      const result = await this.projectService.delete(req.user._id, p_id);
      res.json({status: 'success', 'result': result});
    } catch(err) {
      next(err);
    }
  }

}

module.exports = new ProjectController(projectService);