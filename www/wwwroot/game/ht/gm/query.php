<?php
/*
 Ạ Trạch Mã Nguyên www.lyzwlkj.vip
*/

session_start();
include '../config/config.php';

// ========== Chung: Xác thực CDK (được thực hiện trong mọi yêu cầu, query.php là giao diện chuyên dụng cho proxy CDK) ==========
$qidPost = isset($_POST['qid']) ? intval($_POST['qid']) : 0;
if ($qidPost < 1) exit_notice('Vui lòng chọn khu vực trước', 1);
$quKey = strval($qidPost);
$qu = isset($qus[$quKey]) ? $qus[$quKey] : null;
if (!$qu) exit_notice('Cấu hình khu vực không tồn tại', 1);

$uid = isset($_POST['uid']) ? trim($_POST['uid']) : '';
if ($uid === '') exit_notice('Tài khoản trò chơi không được để trống', 1);
$pswd = isset($_POST['pswd']) ? trim($_POST['pswd']) : '';
if ($pswd === '') exit_notice('Mật khẩu quản trị không được để trống', 1);

// Kết nối MySQL nơi chứa bảng cdk (sử dụng thông tin DB_* của khu vực hiện tại; giống với bảng và trường mà gmquery.php sendvip ghi vào)
if (empty($qu['DB_HOST']) || empty($qu['DB_NAME'])) {
    exit_notice("Khu vực hiện tại chưa được cấu hình thông tin kết nối MySQL!", 1);
}
$mysqlicdk = new mysqli(
    $qu['DB_HOST'],
    $qu['DB_USER'],
    $qu['DB_PSWD'],
    $qu['DB_NAME'],
    isset($qu['DB_PORT']) ? intval($qu['DB_PORT']) : 3306
);
if ($mysqlicdk->connect_errno) exit_notice('Kết nối cơ sở dữ liệu CDK thất bại: ' . $mysqlicdk->connect_error, 1);
$mysqlicdk->set_charset('utf8mb4');

$cdkLv = null;
$stmt = $mysqlicdk->prepare("SELECT lv, pass FROM cdk WHERE uid = ? AND qid = ? LIMIT 1");
$uidSql = strval($uid);
$qidSql = strval($qidPost);
$stmt->bind_param('ss', $uidSql, $qidSql);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_array();
    if ($row['pass'] !== $pswd) exit_notice('Mật khẩu quản trị sai', 1);
    $cdkLv = intval($row['lv']);
} else {
    exit_notice('Tài khoản chưa được ủy quyền', 1);
}
if ($cdkLv < 1 || $cdkLv > 2) exit_notice('Cấp độ ủy quyền bất thường (chỉ hỗ trợ lv=1 hoặc lv=2)', 1);

// "Người chơi mục tiêu cho thao tác nghiệp vụ" trong query.php bắt buộc phải đến từ khung khóa phía trước (userId dạng số)
// Lưu ý: $_POST['uid'] là chuỗi tài khoản ủy quyền dùng để đăng nhập CDK (xác thực cdk.uid/cdk.pass)
//       $_POST['target_userid'] là userId dạng số đã được tìm thấy qua nút 「Tìm kiếm người chơi」 và được khóa lại (đối tượng thao tác)
//       Ý nghĩa của cả hai hoàn toàn khác nhau, không được nhầm lẫn!
$_targetUsr = isset($_POST['target_userid']) ? trim($_POST['target_userid']) : '';

// ========== Chung: Đơn giản hóa và sao chép ierr / parse_item_id từ gmquery.php sang đây (để tránh phụ thuộc chéo giữa các tệp) ==========
if (!function_exists('ierr')) {
    function ierr($r, $prefix = 'Yêu cầu API thất bại', $errno = 2000) {
        $msg = $prefix . '：';
        if (is_array($r) && isset($r['message']))   $msg .= $r['message'];
        elseif (is_array($r) && isset($r['error'])) $msg .= $r['error'];
        else $msg .= json_encode($r, JSON_UNESCAPED_UNICODE);
        if (is_array($r) && !empty($r['code'])) $msg .= '（code=' . $r['code'] . '）';
        exit_notice($msg, $errno);
    }
}
if (!function_exists('proxy_parse_item_id')) {
    // Trích xuất thống nhất ID dạng số từ các định dạng "123;Tên vật phẩm" / "123_Tên vật phẩm" / "123(Tên vật phẩm)" trong getitems.php
    function proxy_parse_item_id($raw) {
        $raw = trim(strval($raw));
        if ($raw === '') return 0;
        if (preg_match('/^(\d+)/', $raw, $m)) return intval($m[1]);
        if (ctype_digit($raw)) return intval($raw);
        $t = preg_replace('/[,;_\s（(].*$/u', '', $raw);
        return ctype_digit($t) ? intval($t) : 0;
    }
}

// ========== Nghiệp vụ cốt lõi ==========
$action = isset($_POST['action']) ? trim($_POST['action']) : '';

/* -----------------------------------------------------------------
 * 0) lookup_player: Tìm kiếm người chơi theo tài khoản đăng nhập (accountId) hoặc tên nhân vật (name)
 * ----------------------------------------------------------------- */
if ($action === 'lookup_player') {
    // Khóa cổng vào thống nhất (logic hoàn toàn giống với lookup_account của gmquery.php):
    //   Độ ưu tiên: POST['account'] (tham số chuẩn mới truyền từ phía trước) > Tương thích: POST['key'] / target_userid làm phương án dự phòng
    $key = isset($_POST['account']) ? trim($_POST['account']) : '';
    if ($key === '') { $key = isset($_POST['key']) ? trim($_POST['key']) : ''; }
    if ($key === '' && $_targetUsr !== '') {
        // Tương thích gọi cũ: nếu lỡ có ai nhét chuỗi cần tìm trực tiếp vào target_userid thì vẫn dùng được (nhưng frontend hiện tại đang đi qua POST['account'])
        $key = $_targetUsr;
    }
    if ($key === '') exit_notice('Vui lòng nhập tài khoản đăng nhập hoặc tên nhân vật cần truy vấn', 1031);
    $q_limit = isset($_POST["limit"]) ? intval($_POST["limit"]) : 0;

    $roles = array();
    $seen  = array();
    $searchedDescription = array();
    $push_one = function($p) use (&$roles, &$seen) {
        $uid = isset($p['userId']) ? strval($p['userId']) : '';
        if ($uid === '' || isset($seen[$uid])) return;
        $seen[$uid] = true;
        $roles[] = $p;
    };
    $build_body = function($mode, $value, $limit) {
        $b = array();
        if ($mode === 'accountId') $b['accountId'] = $value;
        elseif ($mode === 'name')  $b['name']      = $value;
        elseif ($mode === 'userId') $b['userId']   = $value;
        if ($limit > 0) $b['limit'] = $limit;
        return $b;
    };

    $limit_per_phase = ($q_limit > 0) ? $q_limit : 80;

    // Giai đoạn 1: Truy vấn chính xác theo accountId (tài khoản đăng nhập) — giống với hậu trường GM, ưu tiên cao, không cho phép tìm kiếm mờ (fuzzy)
    $acc_key = strtolower(trim($key));
    $r1 = lhs_internal_call('/api/internal/player/lookup', $build_body('accountId', $acc_key, $limit_per_phase), 'POST');
    if ($r1['success'] && isset($r1['data']['roles']) && is_array($r1['data']['roles'])) {
        foreach ($r1['data']['roles'] as $p) $push_one($p);
        $searchedDescription[] = 'Tài khoản=' . $acc_key . '(khớp ' . count($r1['data']['roles']) . ')';
    } else {
        $searchedDescription[] = 'Tài khoản=' . $acc_key . '(không khớp)';
    }

    // Giai đoạn 2: Truy vấn chính xác theo tên nhân vật (phải nhập đúng tên nhân vật, không cho phép mờ)
    $name_key = trim($key);
    $want = ($q_limit > 0) ? $q_limit : 50;
    if (count($roles) < $want && $name_key !== '') {
        $r2 = lhs_internal_call('/api/internal/player/lookup', $build_body('name', $name_key, $limit_per_phase), 'POST');
        $hitName = 0;
        if ($r2['success'] && isset($r2['data']['roles']) && is_array($r2['data']['roles'])) {
            foreach ($r2['data']['roles'] as $p) { $push_one($p); $hitName++; }
            $searchedDescription[] = 'Tên nhân vật(chính xác)=' . $name_key . '(khớp ' . $hitName . ')';
        } else {
            $searchedDescription[] = 'Tên nhân vật(chính xác)=' . $name_key . '(không khớp)';
        }
    }

    if (count($roles) === 0) {
        exit_notice('Không tìm thấy người chơi phù hợp các điều kiện (Đã thử: ' . implode('；', $searchedDescription) . ')', 2006, array('list' => array(), 'total' => 0));
    }
    if ($q_limit > 0 && count($roles) > $q_limit) $roles = array_slice($roles, 0, $q_limit);
    $total = count($roles);

    // Giao diện chỉ hiển thị người chơi đầu tiên (Khóa UserId = roles[0].userId), ở đây đồng bộ hóa nhẹ tên trường trả về khớp với key 'list' của frontend (thực ra là như nhau)
    exit_notice("Đã tìm thấy {$total} người chơi, đã khóa người chơi đầu tiên: " . (isset($roles[0]['name']) ? $roles[0]['name'] : '') . '（' . (isset($roles[0]['userId']) ? $roles[0]['userId'] : '') . '）', 0, array(
        'total' => $total,
        'list'  => $roles,
        // Tương thích key hậu trường GM, sự kiện btn-lookup của index.html thực tế chỉ lấy data.list, thêm vào hay không đều được, thêm cho chắc chắn
        'roles' => $roles,
        'hit'   => $total,
    ));
}

// Bắt buộc kiểm tra trước khi vào action nghiệp vụ: Phải mang theo target_userid (giá trị của khung khóa phía trước)
if ($action !== 'lookup_player' && $_targetUsr === '') {
    exit_notice('Vui lòng nhấp vào nút 「Tìm kiếm người chơi (Khớp chính xác)」 và khóa thành công UserId trước khi thực hiện thao tác này', 1029);
}
$usr = $_targetUsr;

/* -----------------------------------------------------------------
 * 1) sendmoney: Nạp tiền / Gửi tiền tệ
 * ----------------------------------------------------------------- */
if ($action === 'sendmoney') {
    global $RECHARGE_YUAN_PER_DIAMOND_UNIT, $title, $contents;
    if (!isset($RECHARGE_YUAN_PER_DIAMOND_UNIT)) $RECHARGE_YUAN_PER_DIAMOND_UNIT = 10;

    $chargetype = isset($_POST['chargetype']) ? trim($_POST['chargetype']) : '';
    $chargenum  = isset($_POST['chargenum'])  ? trim($_POST['chargenum'])  : '';

    if ($chargetype === '' || $chargetype === '0') exit_notice('Vui lòng chọn loại tiền tệ', 1006);
    if ($chargenum === '' || !ctype_digit($chargenum)) exit_notice('Vui lòng nhập số lượng tiền tệ hợp lệ (số nguyên dương)', 1007);
    $chargenum = intval($chargenum);
    if ($chargenum < 1) exit_notice('Số lượng nạp phải lớn hơn hoặc bằng 1', 1007);
    $chargemax = isset($GLOBALS['chargemax']) ? intval($GLOBALS['chargemax']) : 99999999;
    if ($chargenum > $chargemax) exit_notice('Số lượng nạp vượt quá giới hạn (tối đa ' . $chargemax . '）', 1007);

    // ============ Nhánh 1: Kim cương chargetype=2: Quy trình nạp tiền thực tế (giữ nguyên) ============
    if ($chargetype === '2') {
        $amountYuan = round($chargenum / $RECHARGE_YUAN_PER_DIAMOND_UNIT, 2);
        if ($amountYuan <= 0) exit_notice('Số tiền quy đổi theo tỷ lệ 1 nhân dân tệ = 10 kim cương chưa đủ, vui lòng tăng số lượng kim cương', 1009);
        $orderId = 'CDK_' . date('YmdHis') . '_' . substr(md5($usr . mt_rand(1000, 9999)), 0, 6);
        $payload = array(
            'orderId'           => $orderId,
            'userId'            => $usr,
            'amount'            => $amountYuan,
            'productId'         => '',
            'productName'       => 'Nạp kim cương CDK ' . $chargenum,
            'channel'           => 'cdk_proxy',
            'note'              => 'Nạp tiền trang quản trị người chơi CDK chargetype=2',
            'allowPendingOrder' => true,
            'idempotentSuccess' => true,
        );
        $r = lhs_internal_call('/api/internal/recharge', $payload);
        if (!$r['success']) ierr($r, 'Nạp kim cương thất bại', 2001);

        $d     = is_array($r['data']) ? $r['data'] : array();
        $giv   = isset($d['given'])       ? $d['given']       : array();
        $app   = isset($giv['applied'])   ? $giv['applied']   : array();
        $cur   = isset($app['currencies']) ? $app['currencies'] : array();
        $dmd   = isset($cur['diamond'])   ? intval($cur['diamond'])   : 0;
        $gld   = isset($cur['gold'])      ? intval($cur['gold'])      : 0;
        $slv   = isset($cur['silver'])    ? intval($cur['silver'])    : 0;
        $lv    = isset($app['level'])     ? intval($app['level'])     : 0;
        $exp   = isset($app['exp'])       ? intval($app['exp'])       : 0;
        $ri    = isset($giv['rechargeInfo']) ? $giv['rechargeInfo'] : array();
        $first = isset($ri['firstRecharge']) ? intval($ri['firstRecharge']) : 0;
        // Hoàn toàn giống với hiển thị cửa sổ sendmoney của gmquery.php: Thực phát + Đánh giá trúng nhân đôi nạp đầu + Số tiền + VIP
        $diamondOut = $dmd;
        $vipLevel   = isset($ri['vipLevel']) ? intval($ri['vipLevel']) : 0;
        $firstHint  = '';
        if ($diamondOut > $chargenum) {
            $firstHint = "（Trúng nhân đôi nạp đầu, dự kiến=" . ($chargenum * 2) . "）";
        }
        exit_notice(
            "Nạp tiền thành công: Đơn hàng {$orderId}, thực phát {$diamondOut} nguyên bảo {$firstHint}, quy đổi {$amountYuan} nhân dân tệ, VIP hiện tại={$vipLevel}",
            0,
            array(
                'orderId'    => $orderId,
                'amountYuan' => $amountYuan,
                'diamond'    => $diamondOut,
                'vipLevel'   => $vipLevel,
                'raw'        => isset($r['data']) ? $r['data'] : null,
                // Tương thích trường đã lấy từ frontend trước đó, giữ lại để không bị lỗi
                'silver'     => $slv,
                'gold'       => $gld,
                'level'      => $lv,
                'exp'        => $exp,
                'firstRecharge' => $first,
            )
        );
    }

    // ============ Nhánh 2: Bạc (chargetype=1 → ID vật phẩm=2) / Thiên Diễn Lệnh (1001) / ID vật phẩm khác → Đi qua tệp đính kèm thư ============
    if ($chargetype === '1') {
        $itemId   = 2;
        $itemName = 'Bạc';
    } else {
        $intType = intval($chargetype);
        if ($intType <= 0 || strval($intType) !== trim($chargetype)) {
            exit_notice("Loại chargetype={$chargetype} không được hỗ trợ, hỗ trợ: 1=Bạc(ID=2 thư) / 2=Nguyên bảo(Nạp trực tiếp vào túi) / Số nguyên dương khác=ID vật phẩm (ví dụ: 1001=Thư Thiên Diễn Lệnh)", 1008);
        }
        $itemId   = $intType;
        $itemName = "Vật phẩm ID{$intType}";
        // Tên hiển thị chỉ giữ lại Thiên Diễn Lệnh=1001, các loại khác viết theo ID; không nhắc đến "Nguyên bảo" trong nhánh thư để tránh nhầm lẫn
        if ($intType === 1001) $itemName = 'Thiên Diễn Lệnh';
    }

    $mailPayload = array(
        'userId'      => $usr,
        'title'       => $title,
        'content'     => $contents,
        'expireDays'  => 30,
        'attachments' => array(array('id' => $itemId, 'count' => $chargenum)),
    );
    $r = lhs_internal_call('/api/internal/mail/send', $mailPayload);
    if (!$r['success']) ierr($r, 'Gửi thư thất bại (chưa được giao)', 2010);
    $bizRaw = isset($r['data']) ? $r['data'] : null;
    $bizOk  = is_array($bizRaw) && !empty($bizRaw['success']);
    if (!$bizOk) {
        $bizErr = is_array($bizRaw) && isset($bizRaw['error']) ? strval($bizRaw['error']) : 'Lỗi không xác định';
        ierr($r, 'Gửi thư máy chủ trò chơi thất bại: ' . $bizErr, 2011);
    }
    $total   = isset($bizRaw['total'])   ? intval($bizRaw['total'])   : 1;
    $batchId = isset($bizRaw['batchId']) ? $bizRaw['batchId']         : '';
    exit_notice(
        "Gửi thư thành công: {$itemName}（ID={$itemId}）× {$chargenum} đã được chuyển dưới dạng tệp đính kèm thư, tổng cộng {$total} bức thư (batchId={$batchId})",
        0,
        array(
            'itemName'   => $itemName,
            'itemId'     => $itemId,
            'itemNum'    => $chargenum,
            'chargetype' => $chargetype,
            'total'      => $total,
            'batchId'    => $batchId,
            'mail_raw'   => $r,
        )
    );
}

if ($action === 'sendmail') {
    global $title, $contents;
    if ($cdkLv !== 2) exit_notice('Quyền hạn hậu trường không đủ (gửi vật phẩm yêu cầu lv=2)', 1024);
    $attachId  = isset($_POST["itemid"])    ? proxy_parse_item_id($_POST["itemid"]) : 0;
    $attachNum = isset($_POST["itemnum"])   ? intval($_POST["itemnum"])             : 0;
    $expDays   = isset($_POST["expire_days"]) ? intval($_POST["expire_days"])       : 30;

    $payload = array(
        'userId'      => $usr,
        'title'       => $title,
        'content'     => $contents,
        'expireDays'  => max(1, $expDays),
        'attachments' => array(),
    );

    $hasJson = isset($_POST["attachments"]) && $_POST["attachments"] !== '' && $_POST["attachments"] !== null;
    if ($hasJson) {
        $extra = json_decode($_POST["attachments"], true);
        if (is_array($extra)) {
            $seenKeys = array();
            foreach ($extra as $a) {
                if (!is_array($a) || !isset($a['id']) || !isset($a['count'])) continue;
                $id    = intval($a['id']);
                $count = intval($a['count']);
                if ($id <= 0 || $count <= 0) continue;
                $k = strval($id);
                if (isset($seenKeys[$k])) {
                    $payload['attachments'][$seenKeys[$k]]['count'] += $count;
                } else {
                    $seenKeys[$k] = count($payload['attachments']);
                    $payload['attachments'][] = array('id' => $id, 'count' => $count);
                }
            }
        }
    } else {
        if ($attachId > 0 && $attachNum > 0) {
            $payload['attachments'][] = array('id' => $attachId, 'count' => $attachNum);
        }
    }

    if (empty($payload['attachments'])) {
        exit_notice('Thư cá nhân phải chứa ít nhất 1 tệp đính kèm hợp lệ (ID vật phẩm > 0 và số lượng >= 1), không cho phép gửi thư trống', 1025);
    }

    $r = lhs_internal_call('/api/internal/mail/send', $payload);
    if (!$r['success']) ierr($r, 'Gửi thư thất bại', 2003);
    $attCount = 0;
    foreach ($payload['attachments'] as $_a) $attCount += intval($_a['count']);
    $total = isset($r['data']['total']) ? intval($r['data']['total']) : 1;
    exit_notice(
        "Gửi thư thành công, tổng cộng {$total} bức thư (bao gồm " . count($payload['attachments']) . " loại tệp đính kèm, tổng số {$attCount} cái)",
        0,
        array(
            'batchId'     => isset($r['data']['batchId']) ? $r['data']['batchId'] : null,
            'total'       => $total,
            'attachments' => $payload['attachments'],
        )
    );
}

// Hành động không xác định
exit_notice(
    "Loại thao tác không được hỗ trợ!",
    1,
    array('cdkLv' => $cdkLv, 'uid' => $usr, 'qid' => $qidPost)
);