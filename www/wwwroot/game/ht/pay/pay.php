<?php
/*
 * 阿泽源码网 www.lyzwlkj.vip
*/
// 引入配置文件
include "../config/config.php";

// 1. 获取并初步处理输入
$usr = isset($_POST["usr"]) ? $_POST["usr"] : "";
$quid = isset($_POST["qu"]) ? intval($_POST["qu"]) : 0;
$cdk = isset($_POST["cdk"]) ? $_POST["cdk"] : "";
$pas = isset($_POST["pas"]) ? $_POST["pas"] : "";

// 2. 数据清洗 (去除空格和百分号)
$usr = str_replace([" ", "%"], "", $usr);
$cdk = str_replace([" ", "%"], "", $cdk);
$pas = str_replace([" ", "%"], "", $pas);

// 3. 基础验证
if ($cdk == "") die("Vui lòng nhập CDKEY");
if (!$quid) die("Vui lòng chọn máy chủ");
if ($usr == "") die("Vui lòng nhập tài khoản game hoặc tên nhân vật");
if ($pas == "") die("Vui lòng nhập mật khẩu quản trị");

// 4. 数据库连接
$mysqlcdk = mysqli_connect(
    $PZ["DB_HOST"],
    $PZ["DB_USER"],
    $PZ["DB_PWD"],
    $PZ["DB_NAME"],
    $PZ["DB_PORT"]
) or die("Không thể kết nối cơ sở dữ liệu!");

$mysqlcdk->query("set names utf8");

// 查询 CDK 信息 (使用预处理防止注入)
$stmt_check_cdk = $mysqlcdk->prepare("SELECT * FROM cdk WHERE cdk = ? LIMIT 1");
$stmt_check_cdk->bind_param("s", $cdk); // "s" 代表字符串类型
$stmt_check_cdk->execute();
$result_cdk = $stmt_check_cdk->get_result();
$xxx = $result_cdk->fetch_assoc();
$stmt_check_cdk->close();

// 验证 CDK 有效性
if (!$xxx || $xxx["cdk"] == "") {
    die("Mã cấp quyền không tồn tại");
}
if ($xxx["status"] != 0) {
    die("Mã cấp quyền đã được sử dụng");
}

// 计算目标等级
$lx = $xxx["lv"] + 100;

// 查询当前账号状态 (使用预处理)
$stmt_check_user = $mysqlcdk->prepare("SELECT * FROM cdk WHERE uid = ? AND qid = ? LIMIT 1");
$stmt_check_user->bind_param("si", $usr, $quid); // "s"=字符串, "i"=整数
$stmt_check_user->execute();
$result_user = $stmt_check_user->get_result();
$ss = $result_user->fetch_assoc();
$stmt_check_user->close();

$xlx = $ss["lv"] + 100;

// 逻辑处理与更新
if ($xlx == 100) {
    // 情况1: 账号不存在，进行新授权
    // 使用预处理执行 UPDATE (这里实际上是插入逻辑的变体)
    $stmt_update = $mysqlcdk->prepare("UPDATE cdk SET status = 1, uid = ?, pass = ?, qid = ? WHERE cdk = ?");
    $stmt_update->bind_param("ssis", $usr, $pas, $quid, $cdk);
    
    if ($stmt_update->execute()) {
        die("Đã cấp quyền cho tài khoản game hoặc nhân vật! Hãy giữ an toàn mật khẩu quản trị của bạn.");
    } else {
        die("Cấp quyền thất bại. Vui lòng liên hệ quản trị viên.");
    }
    $stmt_update->close();

} else {
    // 情况2: 账号已存在，进行等级比对
    if ($xlx == $lx) {
        die("Tài khoản game hoặc nhân vật này đã được cấp quyền.");
    } elseif ($xlx < $lx) {
        // 等级不足，先删除旧记录，再更新
        $stmt_del = $mysqlcdk->prepare("DELETE FROM cdk WHERE uid = ? AND qid = ? LIMIT 1");
        $stmt_del->bind_param("si", $usr, $quid);
        $stmt_del->execute();
        $stmt_del->close();

        $stmt_upgrade = $mysqlcdk->prepare("UPDATE cdk SET status = 1, uid = ?, pass = ?, qid = ? WHERE cdk = ?");
        $stmt_upgrade->bind_param("ssis", $usr, $pas, $quid, $cdk);
        
        if ($stmt_upgrade->execute()) {
            die("Đã cấp quyền cho tài khoản game hoặc nhân vật! Hãy giữ an toàn mật khẩu quản trị của bạn.");
        } else {
            die("Cấp quyền thất bại. Vui lòng liên hệ quản trị viên.");
        }
        $stmt_upgrade->close();
        
    } else {
        // xlx > lx
        die("Tài khoản game hoặc nhân vật này đã được cấp quyền.");
    }
}

// 关闭连接
$mysqlcdk->close();
exit();
?>
