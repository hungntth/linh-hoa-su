const Joi = require('joi');
const { AGENT_ROLE_KEYS } = require('./agentRole');
const { validateAllowedUsername } = require('./reservedUsername');

const commissionRateSchema = Joi.object({
  direct: Joi.number().min(0).max(1).optional(),
  level1: Joi.number().min(0).max(1).optional(),
  level2: Joi.number().min(0).max(1).optional(),
  level3: Joi.number().min(0).max(1).optional(),
  level4: Joi.number().min(0).max(1).optional(),
  level5: Joi.number().min(0).max(1).optional(),
});

const agentPermissionsSchema = Joi.object({
  inviteAgent: Joi.boolean().optional(),
  viewTeam: Joi.boolean().optional(),
  viewCommission: Joi.boolean().optional(),
  withdraw: Joi.boolean().optional(),
  setSubCommission: Joi.boolean().optional(),
});

/**
 * 代理商注册参数校验
 */
const registerSchema = Joi.object({
  username: Joi.string().trim().min(3).max(20).custom(validateAllowedUsername).required().messages({
    'string.min': 'Tên đăng nhập phải có ít nhất 3 ký tự',
    'string.max': 'Tên đăng nhập tối đa 20 ký tự',
    'any.required': 'Tên đăng nhập không được để trống',
  }),
  password: Joi.string().min(6).required().messages({
    'string.min': 'Mật khẩu phải có ít nhất 6 ký tự',
    'any.required': 'Mật khẩu không được để trống',
  }),
  phone: Joi.string()
    .pattern(/^1[3-9]\d{9}$/)
    .allow('', null)
    .optional()
    .messages({
      'string.pattern.base': 'Số điện thoại không đúng định dạng',
    }),
  parentInviteCode: Joi.string().length(6).uppercase().required().messages({
    'string.length': 'Mã mời phải có 6 ký tự',
    'any.required': 'Mã mời không được để trống',
  }),
});

/**
 * 代理商登录参数校验
 */
const loginSchema = Joi.object({
  username: Joi.string().required().messages({
    'any.required': 'Tên đăng nhập không được để trống',
  }),
  password: Joi.string().required().messages({
    'any.required': 'Mật khẩu không được để trống',
  }),
});

/**
 * 提现申请参数校验
 */
const withdrawalSchema = Joi.object({
  amount: Joi.number().integer().min(100).required().messages({
    'number.min': 'Số tiền rút tối thiểu là 1 tệ (100 xu)',
    'any.required': 'Số tiền rút không được để trống',
  }),
  bankInfo: Joi.object({
    bankName: Joi.string().required().messages({
      'any.required': 'Tên ngân hàng không được để trống',
    }),
    accountName: Joi.string().required().messages({
      'any.required': 'Tên chủ tài khoản không được để trống',
    }),
    accountNumber: Joi.string().required().messages({
      'any.required': 'Số tài khoản không được để trống',
    }),
  }).required(),
});

/**
 * 充值回调参数校验
 */
const rechargeCallbackSchema = Joi.object({
  playerId: Joi.string().required().messages({
    'any.required': 'ID người chơi không được để trống',
  }),
  orderId: Joi.string().required().messages({
    'any.required': 'ID đơn hàng không được để trống',
  }),
  amount: Joi.number().integer().min(1).required().messages({
    'number.min': 'Số tiền nạp phải lớn hơn 0',
    'any.required': 'Số tiền nạp không được để trống',
  }),
  inviteCode: Joi.string().length(6).uppercase().optional().messages({
    'string.length': 'Mã mời phải có 6 ký tự',
  }),
});

/**
 * 绑定邀请码参数校验
 */
const bindInviteSchema = Joi.object({
  playerId: Joi.string().required().messages({
    'any.required': 'ID người chơi không được để trống',
  }),
  inviteCode: Joi.string().length(6).uppercase().required().messages({
    'string.length': 'Mã mời phải có 6 ký tự',
    'any.required': 'Mã mời không được để trống',
  }),
});

/**
 * 代理商状态变更参数校验
 */
const agentStatusSchema = Joi.object({
  status: Joi.string().valid('pending', 'active', 'rejected', 'suspended', 'banned').required().messages({
    'any.only': 'Trạng thái phải là pending, active, rejected, suspended hoặc banned',
    'any.required': 'Trạng thái không được để trống',
  }),
  reason: Joi.string().allow('', null).messages({
    'string.base': 'Lý do phải là chuỗi',
  }),
});

const adminAgentCreateSchema = Joi.object({
  username: Joi.string().trim().min(3).max(20).custom(validateAllowedUsername).required(),
  password: Joi.string().min(6).required(),
  phone: Joi.string().pattern(/^1[3-9]\d{9}$/).required(),
  businessRole: Joi.string().valid(...AGENT_ROLE_KEYS).required(),
  parentInviteCode: Joi.string().length(6).uppercase().allow('', null),
  status: Joi.string().valid('pending', 'active', 'rejected', 'suspended', 'banned').default('active'),
  commissionRate: commissionRateSchema.optional(),
  permissions: agentPermissionsSchema.optional(),
});

const adminAgentProfileSchema = Joi.object({
  businessRole: Joi.string().valid(...AGENT_ROLE_KEYS).optional(),
  commissionRate: commissionRateSchema.optional(),
  permissions: agentPermissionsSchema.optional(),
}).min(1);

/**
 * 提现审批参数校验
 */
const withdrawalApproveSchema = Joi.object({
  remark: Joi.string().allow('', null).messages({
    'string.base': 'Ghi chú phải là chuỗi',
  }),
});

/**
 * 提现拒绝参数校验
 */
const withdrawalRejectSchema = Joi.object({
  reason: Joi.string().required().messages({
    'any.required': 'Lý do từ chối không được để trống',
  }),
});

const agentReviewSchema = Joi.object({
  action: Joi.string().valid('approve', 'reject').required(),
  reason: Joi.string().allow('', null).when('action', {
    is: 'reject',
    then: Joi.string().trim().min(1).required(),
    otherwise: Joi.string().allow('', null),
  }),
});

const childRoleUpdateSchema = Joi.object({
  businessRole: Joi.string().valid(...AGENT_ROLE_KEYS).required(),
});

const childPasswordResetSchema = Joi.object({
  password: Joi.string().min(6).required().messages({
    'string.min': 'Mật khẩu phải có ít nhất 6 ký tự',
    'any.required': 'Mật khẩu không được để trống',
  }),
});

const accessRoleSchema = Joi.object({
  role: Joi.string().valid('root', 'admin', 'agent').required(),
  accessPermissions: Joi.array().items(Joi.string()).default([]),
});

/**
 * 校验中间件工厂函数
 */
function validate(schema) {
  return (req, res, next) => {
    const { error } = schema.validate(req.body, { abortEarly: false });
    if (error) {
      const errors = error.details.map((detail) => detail.message);
      return res.status(400).json({ success: false, message: errors.join('; ') });
    }
    next();
  };
}

module.exports = {
  registerSchema,
  loginSchema,
  withdrawalSchema,
  rechargeCallbackSchema,
  bindInviteSchema,
  adminAgentCreateSchema,
  adminAgentProfileSchema,
  agentStatusSchema,
  agentReviewSchema,
  childRoleUpdateSchema,
  childPasswordResetSchema,
  accessRoleSchema,
  withdrawalApproveSchema,
  withdrawalRejectSchema,
  validate,
};
