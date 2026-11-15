<?php
/*
 * A Trạch Mã Nguồn www.lyzwlkj.vip
 */
include "../config/config.php";

// ========== Phân tích tham số (tương thích bí danh) ==========
$qu = isset($_REQUEST["qid"]) ? intval($_REQUEST["qid"]) : (isset($_REQUEST["qu"]) ? intval($_REQUEST["qu"]) : 0);
if (!$qu) exit_notice("Vui lòng chọn server trước", 1001);
if (!isset($GLOBALS['qus'][$qu])) exit_notice("Server không tồn tại", 1002);

$code = isset($_REQUEST["gmcode"]) ? $_REQUEST["gmcode"] : (isset($_REQUEST["code"]) ? $_REQUEST["code"] : "");
if (!$code || $code !== $GLOBALS['gmcode']) exit_notice("Mã GM không chính xác", 1004);

$usr = isset($_REQUEST["uid"]) ? trim($_REQUEST["uid"])
     : (isset($_REQUEST["usr"]) ? trim($_REQUEST["usr"])
     : (isset($_REQUEST["user"]) ? trim($_REQUEST["user"]) : ""));
$usr = str_replace([" ", "%"], "", $usr);

$action = isset($_REQUEST["action"]) ? $_REQUEST["action"] : "";

// ========== Hàm hỗ trợ chung ==========
function ierr($r, $prefix, $errno) {
    $msg = isset($r['data']['error']) ? $r['data']['error'] : ($r['error'] ? $r['error'] : 'HTTP ' . $r['http_code']);
    exit_notice($prefix . "：" . $msg, $errno, array('raw' => $r['raw'], 'http_code' => $r['http_code']));
}
function parse_item_id($rawId) {
    $parts = explode("_", $rawId);
    $id = intval(trim($parts[0]));
    return $id > 0 ? $id : 0;
}

// Theo $ITEM_TO_CURRENCY, điền (itemId, count) vào payload give (tiền tệ dùng trường cấp cao nhất, các vật phẩm khác dùng items)
function apply_item_to_give(&$payload, $itemId, $count) {
    global $ITEM_TO_CURRENCY;
    if ($count <= 0) return;
    if (isset($ITEM_TO_CURRENCY[$itemId])) {
        $cur = $ITEM_TO_CURRENCY[$itemId];           // 1=diamond / 2=silver / 6=gold
        $payload[$cur] = isset($payload[$cur]) ? intval($payload[$cur]) + $count : $count;
    } else {
        if (!isset($payload['items']) || !is_array($payload['items'])) $payload['items'] = array();
        $key = strval($itemId);
        $payload['items'][$key] = isset($payload['items'][$key]) ? intval($payload['items'][$key]) + $count : $count;
    }
}

// ========== 1. sendmoney Nạp/phát (nguyên bảo đi theo quy trình nạp và trực tiếp vào túi; bạc/Thiên Diễn Lệnh... đi qua vật phẩm đính kèm trong thư)==========
//  chargetype Hành vi：
//    2 → Nguyên bảo：走 /api/internal/recharge（Quy trình nạp thực tế, tính VIP/tổng nạp/nạp lần đầu, trực tiếp vào túi）
//    1 → Bạc：Dùng /api/internal/mail/send để gửi vật phẩm đính kèm (ID vật phẩm=2 là bạc, người chơi nhận khi lấy thư)
//    其他正整数 N → Coi là ID vật phẩm=N (ví dụ: 1001=Thiên Diễn Lệnh), gửi qua vật phẩm đính kèm trong thư
if ($action === "sendmoney") {
    global $CURRENCY_MAP, $RECHARGE_YUAN_PER_DIAMOND_UNIT, $title, $contents, $chargemax, $ITEM_TO_CURRENCY;

    $chargenum  = isset($_REQUEST["chargenum"])  ? intval($_REQUEST["chargenum"])  : 0;
    $chargetype = isset($_REQUEST["chargetype"]) ? strval($_REQUEST["chargetype"]) : "0";
    if (!$usr) exit_notice("Vui lòng nhập tài khoản người chơi", 1005);
    if ($chargenum <= 0) exit_notice("Số lượng phải lớn hơn 0", 1006);
    if ($chargenum > $chargemax) exit_notice("Số lượng vượt quá giới hạn {$chargemax}", 1007);

    // ============ Nhánh 1: nguyên bảo chargetype=2: quy trình nạp thực tế (không thay đổi)============
    if ($chargetype === '2') {
        $amountYuan = round($chargenum / $RECHARGE_YUAN_PER_DIAMOND_UNIT, 2);
        if ($amountYuan <= 0) exit_notice("Theo tỷ lệ 1 tệ=10 kim cương, số tiền quy đổi không đủ, vui lòng tăng số lượng nguyên bảo", 1009);
        $orderId = 'GM_' . date('YmdHis') . '_' . substr(md5($usr . mt_rand(1000, 9999)), 0, 6);
        $payload = array(
            'orderId'           => $orderId,
            'userId'            => $usr,
            'amount'            => $amountYuan,
            'productId'         => '',
            'productName'       => 'GM nạp nguyên bảo' . $chargenum,
            'channel'           => 'gm',
            'note'              => 'Nạp từ trang quản trị GM chargetype=' . $chargetype,
            'allowPendingOrder' => true,
            'idempotentSuccess' => true,
        );
        $r = lhs_internal_call('/api/internal/recharge', $payload);
        if (!$r['success']) ierr($r, 'Nạp thất bại', 2001);

        $data = isset($r['data']['data']) ? $r['data']['data'] : array();
        $diamondOut = isset($data['diamond']) ? intval($data['diamond']) : $chargenum;
        $vipOut = isset($data['vipLevel']) ? intval($data['vipLevel']) : 0;

        // Lưu ý: nạp lần đầu (totalRecharge từ 0→khác 0) sẽ kích hoạt x2, vì vậy số thực nhận có thể là chargenum*2
        $firstHint = '';
        if ($diamondOut > $chargenum) {
            $firstHint = "（Kích hoạt nạp lần đầu x2, dự kiến=" . ($chargenum * 2) . "）";
        }
        exit_notice(
            "Nạp thành công: đơn hàng {$orderId}，Thực nhận {$diamondOut} Nguyên bảo {$firstHint}，Quy đổi {$amountYuan} 元，VIP hiện tại={$vipOut}",
            0,
            array(
                'orderId'    => $orderId,
                'amountYuan' => $amountYuan,
                'diamond'    => $diamondOut,
                'vipLevel'   => $vipOut,
                'raw'        => isset($r['data']) ? $r['data'] : null,
            )
        );
    }

    // ============ Nhánh 2: bạc (1) và các ID vật phẩm khác → đều gửi qua vật phẩm đính kèm trong thư ============
    // chargetype=1 → ID vật phẩm đính kèm thực tế=2 (bạc)；Các số nguyên dương N khác → ID=N（如 1001=天衍令）
    if ($chargetype === '1') {
        $itemId   = 2;
        $itemName = 'Bạc';
    } else {
        $intType = intval($chargetype);
        if ($intType <= 0 || strval($intType) !== trim($chargetype)) {
            exit_notice("Loại không được hỗ trợ chargetype={$chargetype}，Hỗ trợ: 1=Bạc (ID=2 qua thư) / 2=Nguyên bảo (nạp trực tiếp vào túi) / số nguyên dương khác=ID vật phẩm (ví dụ 1001=thư Thiên Diễn Lệnh)", 1008);
        }
        $itemId   = $intType;
        $itemName = "ID vật phẩm{$intType}";
        if (is_array($ITEM_TO_CURRENCY) && isset($ITEM_TO_CURRENCY[$itemId])) {
            $curName = $ITEM_TO_CURRENCY[$itemId];
            if ($curName === 'diamond') $itemName = 'Nguyên bảo';
            elseif ($curName === 'gold')   $itemName = 'Vàng';
            elseif ($curName === 'silver') $itemName = 'Bạc';
        }
    }

    $mailPayload = array(
        'userId'      => $usr,
        'title'       => $title,
        'content'     => $contents,
        'expireDays'  => 30,
        'attachments' => array(
            array('id' => $itemId, 'count' => $chargenum),
        ),
    );
    $r = lhs_internal_call('/api/internal/mail/send', $mailPayload);
    if (!$r['success']) ierr($r, 'Gửi thư thất bại (chưa gửi được)', 2010);
    $bizRaw = isset($r['data']) ? $r['data'] : null;
    $bizOk  = is_array($bizRaw) && !empty($bizRaw['success']);
    if (!$bizOk) {
        $bizErr = is_array($bizRaw) && isset($bizRaw['error']) ? strval($bizRaw['error']) : 'Lỗi không xác định';
        ierr($r, 'Gửi thư tới server game thất bại: ' . $bizErr, 2011);
    }

    $total   = isset($bizRaw['total'])   ? intval($bizRaw['total'])   : 1;
    $batchId = isset($bizRaw['batchId']) ? $bizRaw['batchId']          : '';
    exit_notice(
        "Gửi thư thành công：{$itemName}（ID={$itemId}）× {$chargenum} đã được gửi dưới dạng vật phẩm đính kèm trong thư, tổng cộng {$total}  thư（batchId={$batchId}）",
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

// ========== 2. senditem phát vật phẩm (ID 1/2/6 tự động chuyển sang trường tiền tệ, các ID khác chuyển sang items)==========
if ($action === "senditem") {
    global $ITEM_TO_CURRENCY, $itemmax, $title, $contents;

    if (!$usr) exit_notice("Vui lòng nhập tài khoản người chơi", 1005);
    $itemid   = isset($_REQUEST["itemid"])  ? $_REQUEST["itemid"]  : "";
    $itemnum  = isset($_REQUEST["itemnum"]) ? intval($_REQUEST["itemnum"]) : 0;
    if ($itemnum <= 0) exit_notice("Số lượng vật phẩm phải lớn hơn 0", 1010);
    if ($itemnum > $itemmax) exit_notice("Số lượng vật phẩm vượt quá giới hạn {$itemmax}", 1011);

    $id = parse_item_id($itemid);
    if ($id <= 0) exit_notice("Vui lòng chọn đúng vật phẩm（{$itemid}）", 1012);

    $payload = array(
        'notifyMail' => isset($_REQUEST["notify_mail"]) ? boolval($_REQUEST["notify_mail"]) : true,
        'title'      => isset($_REQUEST["title"])       ? trim($_REQUEST["title"])           : $title,
        'content'    => isset($_REQUEST["content"])     ? trim($_REQUEST["content"])         : $contents,
    );
    apply_item_to_give($payload, $id, $itemnum);

    $r = lhs_internal_call('/api/internal/player/give/' . rawurlencode($usr), $payload);
    if (!$r['success']) ierr($r, 'Phát thất bại', 2003);

    // Cung cấp cho giao diện một mô tả dễ đọc về "những gì thực tế đã được phát lần này"
    $desc = array();
    foreach (array('diamond','gold','silver') as $c) {
        if (isset($payload[$c]) && intval($payload[$c]) > 0) $desc[] = "{$c} × {$payload[$c]}";
    }
    if (isset($payload['exp']) && intval($payload['exp']) > 0) $desc[] = "exp × {$payload['exp']}";
    if (!empty($payload['items'])) foreach ($payload['items'] as $k => $v) $desc[] = "ID vật phẩm{$k} × {$v}";
    exit_notice(
        "Phát thành công: " . implode('，', $desc) . " đã được cộng vào tài khoản theo thời gian thực",
        0,
        array(
            'itemId'     => $id,
            'itemNum'    => $itemnum,
            'mappedCur'  => isset($ITEM_TO_CURRENCY[$id]) ? $ITEM_TO_CURRENCY[$id] : null,
            'currencies' => isset($r['data']['currencies']) ? $r['data']['currencies'] : null,
            'applied'    => isset($r['data']['applied'])    ? $r['data']['applied']    : null,
        )
    );
}

// ========== 3. sendmail gửi thư（ID vật phẩm đính kèm cũng tuân theo ánh xạ 1/2/6, tiêu đề/nội dung dùng $title/$contents trong config.php）==========
// Thư cá nhân: bắt buộc có ít nhất 1 vật phẩm đính kèm (không cho phép thư không có vật phẩm/chỉ có chữ, khác với thư toàn server sendmail_all được phép để trống)
if ($action === "sendmail") {
    global $title, $contents;

    if (!$usr) exit_notice("Vui lòng nhập tài khoản người chơi", 1005);
    $mailTitle   = $title;
    $mailContent = $contents;
    $attachId    = isset($_REQUEST["itemid"])      ? parse_item_id($_REQUEST["itemid"]) : 0;
    $attachNum   = isset($_REQUEST["itemnum"])     ? intval($_REQUEST["itemnum"])   : 0;
    $expireDays  = isset($_REQUEST["expire_days"]) ? intval($_REQUEST["expire_days"]) : 30;

    $payload = array(
        'userId'      => $usr,
        'title'       => $mailTitle,
        'content'     => $mailContent,
        'expireDays'  => max(1, $expireDays),
        'attachments' => array(),
    );

    $hasAttachmentsJson = isset($_REQUEST["attachments"]) && $_REQUEST["attachments"] !== '' && $_REQUEST["attachments"] !== null;
    if ($hasAttachmentsJson) {
        // Phiên bản mới: JSON attachments do giao diện gửi lên là nguồn dữ liệu chính thức (hỗ trợ tất cả vật phẩm trong player.txt và đã hiển thị id/name)
        // Để tránh trùng lặp: khi có attachments được truyền lên, hoàn toàn bỏ qua cách tạo vật phẩm đính kèm đơn kiểu cũ từ itemid/itemnum
        $extra = json_decode($_REQUEST["attachments"], true);
        if (is_array($extra)) {
            $seenKeys = array();
            foreach ($extra as $a) {
                if (!is_array($a) || !isset($a['id']) || !isset($a['count'])) continue;
                $id = intval($a['id']);
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
        // Tương thích phiên bản cũ: chỉ truyền itemid + itemnum (chỉ hỗ trợ các loại tiền tệ 1/2/6, giữ lại cho gm.html cũ)
        if ($attachId > 0 && $attachNum > 0) {
            $payload['attachments'][] = array('id' => $attachId, 'count' => $attachNum);
        }
    }

    // Kiểm tra bắt buộc thư cá nhân: phải có ít nhất 1 vật phẩm đính kèm hợp lệ (không cho phép vật phẩm đính kèm trống)
    if (empty($payload['attachments'])) {
        exit_notice("Thư cá nhân phải có ít nhất 1 vật phẩm đính kèm hợp lệ (ID vật phẩm>0 và số lượng≥1), không được gửi thư trống", 1025);
    }

    $r = lhs_internal_call('/api/internal/mail/send', $payload);
    if (!$r['success']) ierr($r, '邮件发送失败', 2004);
    $attCount = 0;
    foreach ($payload['attachments'] as $_a) $attCount += intval($_a['count']);
    exit_notice(
        "Gửi thư thành công，共 {$r['data']['total']}  thư（含 " . count($payload['attachments']) . " 种附件，合计 {$attCount} 件）",
        0,
        array(
            'batchId'     => isset($r['data']['batchId']) ? $r['data']['batchId'] : null,
            'total'       => isset($r['data']['total'])       ? $r['data']['total']       : 0,
            'attachments' => $payload['attachments'],
        )
    );
}

// ========== 4. query_recharge Tra cứu tổng quan nạp tiền ==========
if ($action === "query_recharge") {
    if (!$usr) exit_notice("Vui lòng nhập tài khoản người chơi", 1005);
    $r = lhs_internal_call('/api/internal/player/detail/' . rawurlencode($usr), null, 'GET');
    if (!$r['success']) {
        $err = isset($r['data']['error']) ? $r['data']['error'] : ($r['error'] ? $r['error'] : 'HTTP ' . $r['http_code']);
        if ($r['http_code'] == 404 || $err == 'PLAYER_NOT_FOUND') exit_notice("Người chơi không tồn tại", 2005);
        ierr($r, 'Tra cứu thất bại', 2005);
    }
    $p = $r['data']['player'];
    $info = isset($p['rechargeInfo']) ? $p['rechargeInfo'] : array('totalRecharge' => 0, 'vipLevel' => 0);
    exit_notice("Tra cứu thành công", 0, array(
        'userId'        => $p['userId'],
        'playerId'      => isset($p['playerId'])      ? $p['playerId']      : null,
        'name'          => isset($p['name'])          ? $p['name']          : '',
        'level'         => isset($p['level'])         ? $p['level']         : 1,
        'vipLevel'      => isset($info['vipLevel'])   ? $info['vipLevel']   : 0,
        'totalRecharge' => isset($info['totalRecharge']) ? $info['totalRecharge'] : 0,
        'firstRecharge' => isset($info['firstRecharge']) ? $info['firstRecharge'] : 0,
        'currencies'    => isset($p['currencies'])    ? $p['currencies']    : array('diamond' => 0, 'gold' => 0, 'silver' => 0),
        'createTime'    => isset($p['createTime'])    ? $p['createTime']    : 0,
    ));
}

// ========== 5. query_player Chi tiết đầy đủ người chơi ==========
if ($action === "query_player") {
    if (!$usr) exit_notice("Vui lòng nhập tài khoản người chơi", 1005);
    $r = lhs_internal_call('/api/internal/player/detail/' . rawurlencode($usr), null, 'GET');
    if (!$r['success']) {
        $err = isset($r['data']['error']) ? $r['data']['error'] : ($r['error'] ? $r['error'] : 'HTTP ' . $r['http_code']);
        if ($r['http_code'] == 404 || $err == 'PLAYER_NOT_FOUND') exit_notice("Người chơi không tồn tại", 2006);
        ierr($r, 'Tra cứu thất bại', 2006);
    }
    // Truyền trực tiếp dữ liệu thực tế server trả về (ngoài các trường nội bộ như _id / __v, tất cả đều nằm trong player)
    exit_notice("Tra cứu thành công", 0, $r['data']['player']);
}

// ========== 5.5 lookup_account Tìm người chơi theo tài khoản đăng nhập (accountId) hoặc tên nhân vật (name) (trả userId để điền vào #uid) — chỉ hỗ trợ 2 cách này, cấm tra cứu theo userId ==========
if ($action === "lookup_account") {
    // Điểm vào chung key: ưu tiên key > uid
    $key = isset($_REQUEST["key"]) ? trim($_REQUEST["key"]) : '';
    if ($key === '' && $usr !== '') {
        // Tương thích: người dùng trực tiếp nhập tài khoản/tên nhân vật vào ô uid theo cách cũ
        // Lưu ý: nếu có vẻ là userId bắt đầu bằng u_Wk_ thì từ chối (theo yêu cầu phải dùng tài khoản/tên nhân vật)
        if (stripos($usr, 'u_Wk_') === 0 || stripos($usr, 'u_wk_') === 0) {
            exit_notice('Chức năng này chỉ hỗ trợ tài khoản đăng nhập / tên nhân vật, không cho phép tra cứu theo userId', 1032);
        }
        $key = $usr;
    }
    if ($key === '') exit_notice('Vui lòng nhập tài khoản đăng nhập hoặc tên nhân vật cần tra cứu', 1031);
    $q_limit = isset($_REQUEST["limit"]) ? intval($_REQUEST["limit"]) : 0;

    // gmquery bên trong thực hiện kết hợp “accountId chính xác → name chính xác → name gần đúng”，
    // Như vậy giao diện không cần truyền loại, backend tự động thực hiện hai cách khớp theo quy tắc。
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

    $limit_per_phase = ($q_limit > 0) ? $q_limit : 80; // Mỗi giai đoạn lấy tối đa 80 kết quả, đủ để GM xem

    // Giai đoạn 1: tra chính xác theo accountId (tài khoản đăng nhập → thường dùng nhất, ưu tiên cao) — không cho phép tìm gần đúng
    $acc_key = strtolower(trim($key));
    $r1 = lhs_internal_call('/api/internal/player/lookup', $build_body('accountId', $acc_key, $limit_per_phase), 'POST');
    if ($r1['success'] && isset($r1['data']['roles']) && is_array($r1['data']['roles'])) {
        foreach ($r1['data']['roles'] as $p) $push_one($p);
        $searchedDescription[] = '账号=' . $acc_key . '(tìm thấy' . count($r1['data']['roles']) . ')';
    } else {
        $searchedDescription[] = '账号=' . $acc_key . '(không tìm thấy)';
    }

    // Giai đoạn 2: tra chính xác theo tên nhân vật (phải nhập đúng tên nhân vật, không cho phép tìm gần đúng)
    $name_key = trim($key);
    $want = ($q_limit > 0) ? $q_limit : 50;
    if (count($roles) < $want && $name_key !== '') {
        $r2 = lhs_internal_call('/api/internal/player/lookup', $build_body('name', $name_key, $limit_per_phase), 'POST');
        $hitName = 0;
        if ($r2['success'] && isset($r2['data']['roles']) && is_array($r2['data']['roles'])) {
            foreach ($r2['data']['roles'] as $p) { $push_one($p); $hitName++; }
            $searchedDescription[] = '角色名(精确)=' . $name_key . '(tìm thấy' . $hitName . ')';
        } else {
            $searchedDescription[] = '角色名(精确)=' . $name_key . '(không tìm thấy)';
        }
    }

    if (count($roles) === 0) {
        exit_notice('Không tìm thấy người chơi phù hợp (đã thử: ' . implode('；', $searchedDescription) . '）', 2006, array('roles' => array(), 'total' => 0));
    }

    // Cắt kết quả theo limit người dùng yêu cầu
    if ($q_limit > 0 && count($roles) > $q_limit) $roles = array_slice($roles, 0, $q_limit);
    $total = count($roles);

    if ($total === 1) {
        $one = $roles[0];
        exit_notice('Tìm thấy 1 người chơi', 0, array(
            'total'     => 1,
            'hit'       => 1,
            'unique'    => true,
            'userId'    => isset($one['userId'])    ? $one['userId']    : '',
            'accountId' => isset($one['accountId']) ? $one['accountId'] : '',
            'name'      => isset($one['name'])      ? $one['name']      : '',
            'level'     => isset($one['level'])     ? $one['level']     : 1,
            'roles'     => $roles,
        ));
    }

    exit_notice("Tìm thấy {$total}  người chơi, hãy nhấn chọn nhân vật cần thao tác trong danh sách", 0, array(
        'total'  => $total,
        'hit'    => $total,
        'unique' => false,
        'roles'  => $roles,
    ));
}

// ========== 6. sendvip cấp quyền (GM tạo CDK và thông báo người dùng)==========
if ($action === "sendvip") {
    global $qu, $qus;

    $quArr = (isset($qus[$qu]) && is_array($qus[$qu])) ? $qus[$qu] : null;
    if (!$quArr || empty($quArr['DB_HOST']) || empty($quArr['DB_NAME'])) {
        exit_notice("Server hiện tại chưa cấu hình thông tin kết nối MySQL!", 1026);
    }

    $mysqlicdk = new mysqli(
        $quArr['DB_HOST'],
        $quArr['DB_USER'],
        $quArr['DB_PSWD'],
        $quArr['DB_NAME'],
        isset($quArr['DB_PORT']) ? intval($quArr['DB_PORT']) : 3306
    );
    if ($mysqlicdk->connect_errno) {
        exit_notice("Kết nối cơ sở dữ liệu CDK thất bại: " . $mysqlicdk->connect_error . "(hãy cấu hình MySQL của server trước hoặc xác nhận bảng cdk đã được tạo)", 1027);
    }
    $mysqlicdk->set_charset('utf8');

    $vip     = isset($_POST['vip'])     ? trim($_POST['vip'])     : (isset($_REQUEST['vip'])     ? trim($_REQUEST['vip'])     : '');
    $vippswd = isset($_POST['vippswd']) ? trim($_POST['vippswd']) : (isset($_REQUEST['vippswd']) ? trim($_REQUEST['vippswd']) : '');
    if ($vip === '') exit_notice('Vui lòng chọn cấp quyền', 1021);
    if (!$vippswd)  exit_notice('Vui lòng nhập mật khẩu quản trị', 1022);
    if (!$usr)      exit_notice('Vui lòng nhập tài khoản người chơi', 1005);

    $uidSql    = $mysqlicdk->real_escape_string($usr);
    $qidSql    = $mysqlicdk->real_escape_string($qu);
    $vipLvSql  = is_numeric($vip) ? intval($vip) : $mysqlicdk->real_escape_string($vip);
    $pswdSql   = $mysqlicdk->real_escape_string($vippswd);

    $ss = $mysqlicdk->query("SELECT lv FROM cdk WHERE uid = '{$uidSql}' AND qid = '{$qidSql}' LIMIT 1");
    $xlx = 0;
    if ($ss && ($row = $ss->fetch_array())) {
        $xlx = intval($row['lv']) + 100;
    }
    $lvVal = is_numeric($vip) ? intval($vip) : (is_array($vip) && isset($vip['lv']) ? intval($vip['lv']) : 0);
    $lx = $lvVal + 100;

    if ($xlx == $lx) exit_notice('Nhân vật này đã là người dùng được cấp quyền, không cần mở lại!', 0);
    if ($xlx >  $lx) exit_notice('Nhân vật này đã là người dùng được cấp quyền, không cần mở lại!', 0);

    if ($xlx < $lx) {
        $result = $mysqlicdk->query("DELETE FROM cdk WHERE uid = '{$uidSql}' AND qid = '{$qidSql}' LIMIT 1");
        if ($result) {
            $mysqlicdk->query("INSERT INTO cdk (cdk,lv,qid,uid,pass,status) VALUES ('gmsq','{$vipLvSql}','{$qidSql}','{$uidSql}','{$pswdSql}','1')");
        }
        exit_notice('Cấp quyền nhân vật thành công!' . $usr . '！', 0, array('uid' => $usr, 'lv' => $lvVal, 'qid' => $qu));
    }
}

// ========== 7. sendmail_all gửi thư hàng loạt theo server（SERVER_ID 从 config.php 当前选区 $qus[$qu] 取，前端不传 scope/serverId）==========
if ($action === "sendmail_all") {
    global $title, $contents, $qus, $qu;

    $titleIn   = isset($_REQUEST["title"])      ? trim($_REQUEST["title"])      : "";
    $contentIn = isset($_REQUEST["content"])    ? trim($_REQUEST["content"])    : "";
    $expDays   = isset($_REQUEST["expire_days"]) ? intval($_REQUEST["expire_days"]) : 30;
    $attachRaw = isset($_REQUEST["attachments"]) ? $_REQUEST["attachments"] : "";

    if ($titleIn === "" || $contentIn === "") exit_notice("Vui lòng nhập tiêu đề và nội dung thư", 1023);

    // SERVER_ID lấy trực tiếp từ server hiện tại $qus[$qu] trong config.php (server 1=1001), giao diện không truyền và cũng không cho phép ghi đè
    $quName = trim($qus[$qu]['name']);
    $serverId = intval($qus[$qu]['SERVER_ID']);
    if ($serverId <= 0) exit_notice("Server hiện tại ({$quName}）chưa cấu hình SERVER_ID, vui lòng bổ sung trong config.php dòng " . (86 + $qu) . "   \$qus['{$qu}']  để thêm  'SERVER_ID' => N", 1031);

    $attachments = array();
    if (is_string($attachRaw) && $attachRaw !== "") {
        $tmp = json_decode($attachRaw, true);
        if (is_array($tmp)) $attachments = $tmp;
    } elseif (is_array($attachRaw)) {
        $attachments = $attachRaw;
    }
    $safeAttach = array();
    foreach ($attachments as $a) {
        if (!is_array($a)) continue;
        $id = isset($a['id']) ? intval($a['id']) : 0;
        $cnt = isset($a['count']) ? intval($a['count']) : 0;
        if ($id > 0 && $cnt > 0) $safeAttach[] = array('id' => $id, 'count' => $cnt);
    }

    $payload = array(
        'scope'       => 'server',
        'serverId'    => $serverId,
        'title'       => $titleIn ? $titleIn : $title,
        'content'     => $contentIn ? $contentIn : $contents,
        'attachments' => $safeAttach,
        'expireDays'  => max(1, min(365, $expDays)),
    );

    $r = lhs_internal_call('/api/internal/mail/sendAll', $payload);
    if (!$r['success']) ierr($r, 'Gửi thư hàng loạt thất bại', 2007);

    $total   = isset($r['data']['total'])           ? intval($r['data']['total'])           : 0;
    $batchId = isset($r['data']['batchId'])         ? $r['data']['batchId']                  : '';
    $attCnt  = isset($r['data']['attachmentCount']) ? intval($r['data']['attachmentCount'])   : count($safeAttach);

    exit_notice(
        "Gửi thư hàng loạt thành công：{$quName}（SERVER_ID={$serverId}），共 {$total}  thư，附件 {$attCnt} 种（batchId={$batchId}）",
        0,
        array(
            'scope'           => 'server',
            'serverId'        => $serverId,
            'quId'            => $qu,
            'quName'          => $quName,
            'batchId'         => $batchId,
            'total'           => $total,
            'attachmentCount' => $attCnt,
            'attachments'     => $safeAttach,
        )
    );
}

exit_notice("Loại thao tác không được hỗ trợ!", 1030);
