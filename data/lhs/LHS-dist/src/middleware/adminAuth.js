const { hasPermission } = require('../utils/permissionTree');

/**
 * 管理员权限检查中间件
 */
function requireAdmin(req, res, next) {
  if (!req.agent) {
    return res.status(401).json({
      success: false,
      message: 'Chưa đăng nhập',
    });
  }

  if (!hasPermission(req.agent, 'admin')) {
    return res.status(403).json({
      success: false,
      message: 'Cần quyền quản trị viên',
    });
  }

  next();
}

function requirePermission(permission) {
  return (req, res, next) => {
    if (!req.agent) {
      return res.status(401).json({ success: false, message: 'Chưa đăng nhập' });
    }

    if (!hasPermission(req.agent, permission)) {
      return res.status(403).json({ success: false, message: 'Không đủ quyền' });
    }

    next();
  };
}

const { authenticate, authenticateInternal } = require('./auth');

module.exports = { authenticate, authenticateInternal, requireAdmin, requirePermission };
