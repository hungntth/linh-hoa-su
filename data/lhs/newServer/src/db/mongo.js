/**
 * MongoDB 连接管理
 * 使用 Mongoose 连接 MongoDB，提供连接/断开/健康检查
 */

const mongoose = require('mongoose');
const logger = require('../logger');

function redactMongoUri(uri) {
  return String(uri || '').replace(/(mongodb(?:\+srv)?:\/\/)([^@/]+)@/i, '$1***:***@');
}

// 默认连接配置
const DEFAULT_URI = process.env.MONGO_URI || 'mongodb://127.0.0.1:27017/lhs_db';

function readPositiveInt(name, fallback, min = 1) {
  const value = Number(process.env[name]);
  return Number.isFinite(value) && value >= min ? Math.floor(value) : fallback;
}

const SERVER_SELECTION_TIMEOUT_MS = readPositiveInt('MONGO_SERVER_SELECTION_TIMEOUT_MS', 5000, 500);
const CONNECT_TIMEOUT_MS = readPositiveInt('MONGO_CONNECT_TIMEOUT_MS', 5000, 500);
const SOCKET_TIMEOUT_MS = readPositiveInt('MONGO_SOCKET_TIMEOUT_MS', 10000, 1000);
const BUFFER_TIMEOUT_MS = readPositiveInt('MONGO_BUFFER_TIMEOUT_MS', 3000, 500);

mongoose.set('bufferTimeoutMS', BUFFER_TIMEOUT_MS);

/**
 * 连接 MongoDB
 * @param {string} [uri] - 连接地址，默认从环境变量读取
 * @returns {Promise<mongoose.Connection>}
 */
async function connect(uri) {
  const connUri = uri || DEFAULT_URI;
  // Retry so the server waits for MongoDB (e.g. Docker starting containers in parallel) instead of exiting.
  const maxAttempts = readPositiveInt('MONGO_CONNECT_RETRIES', 1);
  const retryDelayMs = readPositiveInt('MONGO_CONNECT_RETRY_DELAY_MS', 2000, 100);
  for (let attempt = 1; ; attempt += 1) {
    try {
      await mongoose.connect(connUri, {
        serverSelectionTimeoutMS: SERVER_SELECTION_TIMEOUT_MS,
        connectTimeoutMS: CONNECT_TIMEOUT_MS,
        socketTimeoutMS: SOCKET_TIMEOUT_MS,
      });
      logger.info(`[MongoDB] 已连接: ${redactMongoUri(connUri)}`);
      return mongoose.connection;
    } catch (err) {
      if (attempt >= maxAttempts) {
        logger.error(`[MongoDB] 连接失败: ${err.message}`);
        throw err;
      }
      logger.warn(`[MongoDB] 连接失败 (${attempt}/${maxAttempts}), ${retryDelayMs}ms 后重试: ${err.message}`);
      await new Promise((resolve) => setTimeout(resolve, retryDelayMs));
    }
  }
}

/**
 * 断开 MongoDB 连接
 */
async function disconnect() {
  await mongoose.disconnect();
  logger.info('[MongoDB] 已断开连接');
}

/**
 * 获取连接状态
 * @returns {number} 0=断开, 1=已连接, 2=连接中, 3=断开中
 */
function getState() {
  return mongoose.connection.readyState;
}

/**
 * 健康检查
 * @returns {boolean}
 */
function isConnected() {
  return mongoose.connection.readyState === 1;
}

module.exports = {
  connect,
  disconnect,
  getState,
  isConnected,
  mongoose,
};
