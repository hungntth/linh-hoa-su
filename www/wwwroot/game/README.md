# 灵画师 H5 客户端本地镜像

来源: `http://192.168.200.130:18081/index.html?backendHost=192.168.200.130&httpPort=18080&wsPort=9200`
镜像时间: 2026-08-01
引擎: Egret 5 (webgl), 720x1280 竖屏

## 内容
| 路径 | 说明 |
|---|---|
| `index.html` | 入口，内嵌 manifest + 协议改写逻辑 |
| `js/` | 引擎与游戏代码（main.min ~8.8MB 为游戏主逻辑） |
| `update/resource/default.res_*.bin` | RES 资源配置（zlib 压缩 + 自定义二进制序列化） |
| `update/resource/assets/` | 全部资源（图片/音频/spine/配置/Excel/代码资源） |

统计: 44500 个文件, 约 1.93 GB
- 图片 png 18399 / jpg 2500 / ktx 20598(压缩纹理变体)
- 音频 mp3 680
- spine skel 2239
- 配置 bin 56 / json 14

## 本地启动
```powershell
cd "C:\Users\admin\Documents\123 2\ctf_linghuashi\lhs_h5_mirror"
python -m http.server 18080
```
浏览器打开:
- 纯本地壳: `http://127.0.0.1:18080/index.html`
- 连目标后端: `http://192.168.200.130:18081/index.html?backendHost=192.168.200.130&httpPort=18080&wsPort=9200`

注意: 客户端只是 H5 前端。登录/战斗等需要后端 `gameMsg.do`(HTTP) + `ws://...:9200`，纯本地运行时后端不可用，会卡在连接阶段。

## 说明
- 服务器上 404 的资源（5551 个，多为 lang_tw/lang_cn 多语言缺失变体）未镜像，清单见 `tools/download_failed.txt`
- 服务器对部分二进制资源做 zlib 压缩存储（`x\x9c` 开头），客户端运行时自动解压；镜像保持服务器原始字节
- `tools/parsed_configs/` 为资源配置解析结果（JSON），`tools/mirror_download.py` 为可断点续传的镜像脚本

## 本地代理模式（推荐）
`local_proxy.py` 同时提供:
- 127.0.0.1:18080 — 静态资源走本地镜像，API (`/gameMsg.do` 等) 自动转发到目标 `http://192.168.200.130:18080`
- 127.0.0.1:9200 — WebSocket 转发到目标 `ws://192.168.200.130:9200`

浏览器打开 **不带参数** 的地址即可全走本地:
```
http://127.0.0.1:18080/index.html
```
(index.html 默认 backendHost=127.0.0.1, 所有请求同源本地, 无 CORS)

启动: `python local_proxy.py` (当前 PID 15704)
停止: `Get-Process -Id 15704 | Stop-Process`
