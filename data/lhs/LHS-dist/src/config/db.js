const mongoose = require('mongoose');
const config = require('./index');

/**
 * 连接 MongoDB 数据库
 */
async function connectDB() {
  // Retry so the service waits for MongoDB (e.g. Docker starting containers in parallel) instead of exiting.
  const maxAttempts = Math.max(1, Number.parseInt(process.env.MONGO_CONNECT_RETRIES || '1', 10) || 1);
  const retryDelayMs = Math.max(100, Number.parseInt(process.env.MONGO_CONNECT_RETRY_DELAY_MS || '2000', 10) || 2000);
  for (let attempt = 1; ; attempt += 1) {
    try {
      await mongoose.connect(config.mongodb.uri, { serverSelectionTimeoutMS: 5000 });
      console.log('MongoDB 连接成功');
      return;
    } catch (error) {
      if (attempt >= maxAttempts) {
        console.error('MongoDB 连接失败:', error.message);
        process.exit(1);
      }
      console.warn(`MongoDB 连接失败 (${attempt}/${maxAttempts}), ${retryDelayMs}ms 后重试:`, error.message);
      await new Promise((resolve) => setTimeout(resolve, retryDelayMs));
    }
  }
}

/**
 * 断开 MongoDB 连接
 */
async function disconnectDB() {
  try {
    await mongoose.disconnect();
    console.log('MongoDB 断开连接');
  } catch (error) {
    console.error('MongoDB 断开连接失败:', error.message);
  }
}

module.exports = { connectDB, disconnectDB };
