# 📢 投诉建议系统 & CompEase

一个简单高效的投诉建议管理系统、轻量化，支持自定义字段、附件上传、进度跟踪等功能。

---

## 🚀 功能特点  
- 支持业务分类+子业务管理  
- 📝 自定义业务字段（文本/数字/单选/多选）  
- 📁 文件上传+图片预览  
- 🔍 投诉进度实时跟踪  
- 💬 管理员回复+内部备注  
- ➕ 用户可追加回复  
- 🌙 黑暗模式支持  
- 📱 响应式界面设计  

---

## 🛠️ 技术栈  
- **前端**: Vue 3 + Bootstrap 5  
- **后端**: PHP (无框架)  
- **数据库**: SQLite 3  
- **存储**: 本地文件系统  

---

## 📦 部署方法  
1. 上传文件到网站目录：  
   → `frontend/public/*` → 网站根目录  
   → `backend/public/*` → api子目录  
   → `storage/` → 上传目录（需写权限）  

2. 前端伪静态配置：  
   🖥️ Nginx → `try_files $uri $uri/ /index.html`  
   🖥️ Apache → 启用重写规则  
   🖥️ IIS → 启用 URL 重写模块  
   🖥️ 宝塔面板 → 启用 URL 重写模块  
   🖥️ 其他 → 根据实际情况配置
3. 后端伪静态配置：  
   🖥️ Nginx → `location / {
        try_files $uri $uri/ /index.php?$query_string;
}`
   🖥️ Apache → 启用重写规则  
   🖥️ IIS → 启用 URL 重写模块  
   🖥️ 宝塔面板 → 启用 URL 重写模块  
   🖥️ 其他 → 根据实际情况配置

4. 设置权限：  
   ```bash
   chmod -R 755 storage/ database/
   ```
5. 更改前端Api地址：
   → `frontend/public/index.html`
   → `BASE_URL: 'http://' // 后端地址`

6. 访问系统：  
   👨💼 默认管理员账号：admin / admin123  

---

## ❓ 常见问题  
**Q: 上传目录报错**  
✅ 检查 storage 目录权限，确保 PHP 有写入权限  

**Q: 数据库锁定**  
✅ 改用 MySQL 或优化事务处理  

**Q: 图片上传失败**  
✅ 检查目录权限和 PHP 上传限制  

---

## ✨ 项目优势  
- ✅ 零依赖极简部署  
- ✅ 字段自定义灵活  
- ✅ 响应式+黑暗模式  
- ✅ 完整权限管理  
- ✅ 代码规范易维护  

---

## 📚 API 文档  

### 🔑 认证接口  
**POST /api/login**  
- 参数：`username`, `password`  
- 返回：`token`  

---

### 📥 投诉接口  
**POST /api/complaints**  
- 参数：`title`, `description`, `business_id`, `contact`, `attachments`  
- 返回：`id`  

**GET /api/complaints/{id}**  
- 返回：完整投诉信息  

**POST /api/complaints/{id}/replies**  
- 参数：`content`  

---

### 🗂️ 分类接口  
**GET /api/categories**  
- 返回：分类数组  

**GET /api/businesses**  
- 参数：`category_id`（可选）  

---

### 👮 管理接口  
**GET /api/admin/complaints**  
- 🔒 需要认证  
- 参数：`status`（可选）  

**PUT /api/admin/complaints/{id}**  
- 参数：`status`  

**POST /api/admin/businesses**  
- 参数：`name`, `category_id`, `fields_config`  

---

> 📝 完整接口文档详见代码注释
