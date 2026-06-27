<?php
error_reporting(E_ALL^E_NOTICE^E_WARNING);
include './inc/phpQrcode.class.php';
include './inc/poster.class.php';
header('Content-Type: image/png');

// ===================== 核心配置 =====================
$prob = [
    'star5' => 3,      // 五星总概率3%
    'star4' => 14,
    'star3' => 83
];

// ===================== 新增：用户ID + 100抽保底五星 =====================
$user_id     = $_GET['user_id'] ?? '';          // 调用：index.php?user_id=10001
$max_draw    = 100;                             // 100抽必出五星
$counter_file = './draw_counter.txt';            // 计数文件

// ===================== 新增：用户抽卡数据配置 =====================
$user_data_dir = './user_data/';                // 用户数据文件夹
$new_icon_config = [                            // NEW图标配置
    'path' => 'img/new_badge.png',              // NEW图标路径
    'scale' => 1.0,                             // 缩放比例
    'offset_x' => -150,                           // X偏移（右上角）
    'offset_y' => 50,                           // Y偏移
    'width' => 70,                              // 显示宽度
    'height' => 22                              // 显示高度
];
$count_icon_config = [                          // 次数图标配置（1-5次）
    'path_prefix' => 'img/count_',              // 图标路径前缀（count_1.png, count_2.png...）
    'path_suffix' => '.png',
    'scale' => 0.8,                             // 缩放比例                      
    'offset_x' => 80,                            // 新增：X偏移（底部居中，负数向左，正数向右）
    'offset_y' => 290,                          // Y偏移（底部居中，负数向上）
    'width' => 92,                              // 显示宽度
    'height' => 74                              // 显示高度
];
$gold_line_icon_config = [                      // 金线图标配置（7次及以上）
    'path' => 'img/gold_line.png',              // 金线图标路径
    'scale' => 1.5,                             // 缩放比例
    'offset_x' => 80,                            // 新增：X偏移（底部居中，负数向左，正数向右）
    'offset_y' => 290,                          // Y偏移（底部）
    'width' => 40,                             // 显示宽度
    'height' => 40                              // 显示高度
];

// ===================== 新增：用户ID显示配置 =====================
$user_id_config = [
    'font_path' => __DIR__ . '/font/light.ttf', // 绝对路径
    'font_size' => 20,                         // 字体大小
    'font_color' => [255, 255, 255],           // 字体颜色（RGB：白色）
    'shadow_color' => [0, 0, 0],               // 文字阴影颜色（RGB：黑色）
    'shadow_offset_x' => 1,                    // 阴影X偏移
    'shadow_offset_y' => 1,                    // 阴影Y偏移
    'offset_x' => 50,                          // 右下角X偏移（负数向左，正数向右）
    'offset_y' => 50,                          // 右下角Y偏移（负数向上，正数向下）
    'background' => false,                      // 是否显示背景框
    'bg_color' => [0, 0, 0, 80],               // 背景框颜色（RGBA：黑色半透明）
    'bg_padding' => [15, 10],                  // 背景框内边距（左右，上下）
];

// GD字体路径环境变量，解决找不到字体
putenv('GDFONTPATH=' . realpath(__DIR__ . '/font'));

// 创建用户数据目录
if (!is_dir($user_data_dir)) {
    mkdir($user_data_dir, 0777, true);
}

// 读取计数
$counter = [];
if (file_exists($counter_file)) {
    $counter = json_decode(file_get_contents($counter_file), true) ?: [];
}
// 初始化用户计数
if (!isset($counter[$user_id]) || $counter[$user_id] < 0) {
    $counter[$user_id] = 0;
}

// ===================== 新增：加载/初始化用户抽卡数据 =====================
function loadUserDrawData($user_id, $user_data_dir) {
    $user_file = $user_data_dir . $user_id . '.json';
    $default_data = [
        'total_draws' => 0,         // 总抽卡次数
        'roles' => []               // 角色获取次数：key=角色文件名，value=次数
    ];
    
    if (!file_exists($user_file)) {
        file_put_contents($user_file, json_encode($default_data, JSON_PRETTY_PRINT));
        return $default_data;
    }
    
    $data = json_decode(file_get_contents($user_file), true) ?: $default_data;
    // 兼容旧数据结构
    if (!isset($data['total_draws'])) $data['total_draws'] = 0;
    if (!isset($data['roles'])) $data['roles'] = [];
    
    return $data;
}

// ===================== 新增：更新用户抽卡数据 =====================
function updateUserDrawData($user_id, $user_data_dir, $role_file, $is_ten_draw = true) {
    $user_file = $user_data_dir . $user_id . '.json';
    $data = loadUserDrawData($user_id, $user_data_dir);
    
    // 更新总抽卡次数（十连则+10，单抽+1）
    $data['total_draws'] += $is_ten_draw ? 1 : 1;
    
    // 获取角色文件名（作为唯一标识）
    $role_key = basename($role_file);
    
    // 更新角色获取次数
    if (!isset($data['roles'][$role_key])) {
        $data['roles'][$role_key] = 1;
    } else {
        $data['roles'][$role_key]++;
    }
    
    // 保存数据
    file_put_contents($user_file, json_encode($data, JSON_PRETTY_PRINT));
    
    // 返回当前角色的获取次数
    return $data['roles'][$role_key];
}

// ===================== 新增：获取角色获取次数 =====================
function getRoleDrawCount($user_id, $user_data_dir, $role_file) {
    $data = loadUserDrawData($user_id, $user_data_dir);
    $role_key = basename($role_file);
    return isset($data['roles'][$role_key]) ? $data['roles'][$role_key] : 0;
}

// ===================== 新增：自动扫描角色并更新role_alias.json =====================
function autoUpdateRoleAlias($attrList) {
    $alias_file = './role_alias.json';
    // 1. 加载现有别名配置
    $role_alias = file_exists($alias_file) ? json_decode(file_get_contents($alias_file), true) ?: [] : [];
    
    // 2. 扫描所有角色图片文件
    $all_role_files = [];
    foreach(['star3','star4','star5'] as $star) {
        foreach($attrList as $attr) {
            $role_dir = "img/role/$star/$attr";
            if (is_dir($role_dir)) {
                $files = scandir($role_dir);
                foreach($files as $file) {
                    if ($file === '.' || $file === '..') continue;
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if ($ext === 'png') { // 只处理PNG图片
                        $all_role_files[] = $file; // 保存文件名（不含路径）
                    }
                }
            }
        }
    }
    
    // 3. 去重并补充未配置的角色
    $all_role_files = array_unique($all_role_files);
    foreach($all_role_files as $file) {
        if (!isset($role_alias[$file])) {
            $role_alias[$file] = []; // 新增：空别名数组
        }
    }
    
    // 4. 保存更新后的配置
    file_put_contents($alias_file, json_encode($role_alias, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    return $role_alias;
}

// ===================== 新增：加载角色别名配置（核心修改1） =====================
$attrList = ['火','风','水','光','暗']; // 提前定义属性列表供自动扫描使用
$role_alias = autoUpdateRoleAlias($attrList); // 自动更新并加载别名配置

// ===================== 新增：别名转换函数（核心修改2） - 支持模糊搜索 =====================
function getRealRoleFileName($alias, $role_alias) {
    // 如果本身是真实文件名，直接返回
    if (isset($role_alias[$alias])) {
        return $alias;
    }
    
    // 精确匹配别名
    foreach ($role_alias as $real_name => $aliases) {
        if (in_array($alias, $aliases)) {
            return $real_name;
        }
    }
    
    // 模糊搜索：支持部分匹配（不区分大小写）
    $alias_lower = strtolower($alias);
    $matches = [];
    
    // 搜索真实文件名
    foreach (array_keys($role_alias) as $real_name) {
        if (strpos(strtolower($real_name), $alias_lower) !== false) {
            $matches[] = $real_name;
        }
    }
    
    // 搜索别名
    if (empty($matches)) {
        foreach ($role_alias as $real_name => $aliases) {
            foreach ($aliases as $a) {
                if (strpos(strtolower($a), $alias_lower) !== false) {
                    $matches[] = $real_name;
                    break; // 找到一个匹配即可，避免重复
                }
            }
        }
    }
    
    // 如果有模糊匹配结果，返回第一个匹配项
    if (!empty($matches)) {
        return $matches[0];
    }
    
    // 没有匹配的别名，返回原名称
    return $alias;
}

// ===================== 新增：五星UP角色配置 =====================
// 改动：只需要传入up_file（角色名.png），无需指定up_attr
$up_file_raw = $_GET['up_file'] ?? '';
// 转换别名到真实文件名（核心修改3）
$up_file = getRealRoleFileName($up_file_raw, $role_alias);

$upRole = [
    'star' => $_GET['up_star'] ?? 'star5',
    'file' => $up_file  // 使用转换后的真实文件名
];
$upProb = 1.5;
$other5StarProb = $prob['star5'] - $upProb;

// 横屏 16:9 背景
$bg_w = 1600;
$bg_h = 900;

// 卡片大小
$card_w = 160;
$card_h = 360;
$gap    = -30;

// 1行10列
$cols = 10;
$rows = 1;

// 属性 & 星级（已提前定义，此处保留注释）
// $attrList = ['火','风','水','光','暗'];

// ===================== 可调节配置 =====================
$attr_icon_w = 30;
$attr_icon_h = 30;
$attr_icon_offset_x = 65;
$attr_icon_v_align = 'center';
$attr_icon_v_offset = 150;

// 星级图标配置（核心修改）
$single_star_icon_w = 28;  // 单颗星星的宽度
$single_star_icon_h = 28;  // 单颗星星的高度
$star_scale = 0.7;         // 星星整体缩放比例（可自定义）
$star_spacing = -3;         // 星星之间的间距（可自定义）
$star_offset_x = -40;      // 星星组整体X偏移（可自定义，负数向左，正数向右）
$star_offset_y = -30;      // 星星组整体Y偏移（可自定义，负数向上，正数向下）
$star_align = 'center';     // 星星组对齐方式：left/center/right（可自定义）

$top_border1_offset_y = -125;
$top_border2_offset_y = 255;

// 分星级配置顶层边框参数（核心修改）
// 3星沿用原有默认值，4星和5星独立配置
$top_border_config = [
    'star3' => [
        'scale' => 0.64,
        'border1_offset_y' => -125,
        'border2_offset_y' => 255
    ],
    'star4' => [
        'scale' => 0.59,       // 4星缩放比例
        'border1_offset_y' => -110, // 4星第一个边框y偏移
        'border2_offset_y' => 250  // 4星第二个边框y偏移
    ],
    'star5' => [
        'scale' => 0.60,      // 5星缩放比例
        'border1_offset_y' => -120, // 5星第一个边框y偏移
        'border2_offset_y' => 260  // 5星第二个边框y偏移
    ]
];

// ===================== UP角色角标配置（新增核心配置） =====================
$up_badge_config = [
    'path' => 'img/up_badge.png', // UP角标图片路径
    'scale' => 0.8,               // 角标缩放比例（可自定义）
    'offset_x' => 15,              // 角标X偏移（正数向右，负数向左）
    'offset_y' => 40,              // 角标Y偏移（正数向下，负数向上）
    'width' => 60,                // 角标显示宽度
    'height' => 60                // 角标显示高度
];

$frame3_scale = 1.065;
$frame3_offset_x = 1;
$frame3_offset_y = 0;

// ===================== 路径 =====================
$bg_base      = 'img/bg.png';
$frame1       = 'img/frame/frame1.png';
$frame2       = 'img/frame/frame2.png';
$frame3       = 'img/frame/frame3.png';
$star_frame   = [
    'star3' => 'img/star_frame/star3.png',
    'star4' => 'img/star_frame/star4.png',
    'star5' => 'img/star_frame/star5.png',
];
$top_star_frame = [
    'star3' => 'img/star_frame/top_star3.png',
    'star4' => 'img/star_frame/top_star4.png',
    'star5' => 'img/star_frame/top_star5.png',
];
$top_star_frame2 = [
    'star3' => 'img/star_frame/top_star3_2.png',
    'star4' => 'img/star_frame/top_star4_2.png',
    'star5' => 'img/star_frame/top_star5_2.png',
];
$star_icon    = 'img/star_icon/star.png'; // 修改：单张星星图标路径
$attr_icon_path = 'img/attr_icon/';

// ===================== 工具函数 =====================
function getPngFiles($folder){
    $files = [];
    if(!is_dir($folder)) return $files;
    $dir = opendir($folder);
    while(($file = readdir($dir)) !== false){
        if($file === '.' || $file === '..') continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if($ext === 'png') $files[] = $folder.'/'.$file;
    }
    closedir($dir);
    return $files;
}

// 改动：自动查找UP角色的完整路径（无需指定属性）
function getUpRoleFullPath($upRole, $attrList) {
    if(empty($upRole['file'])) return '';
    
    $star = $upRole['star'];
    $fileName = $upRole['file'];
    
    // 遍历所有属性目录查找该文件
    foreach($attrList as $attr) {
        $path = "img/role/{$star}/{$attr}/{$fileName}";
        if(file_exists($path)) {
            // 返回路径和对应的属性
            return [
                'path' => $path,
                'attr' => $attr
            ];
        }
    }
    return '';
}

function drawImageNoStretch($dstCanvas, $imgPath, $dstX, $dstY, $dstW, $dstH) {
    if (!file_exists($imgPath)) return;
    $srcImg = imagecreatefrompng($imgPath);
    imagesavealpha($srcImg, true);
    imagealphablending($srcImg, true);
    $srcW = imagesx($srcImg);
    $srcH = imagesy($srcImg);
    $scale = min($dstW / $srcW, $dstH / $srcH);
    $newW = $srcW * $scale;
    $newH = $srcH * $scale;
    $drawX = $dstX + ($dstW - $newW) / 2;
    $drawY = $dstY + ($dstH - $newH) / 2;
    imagecopyresampled($dstCanvas, $srcImg, $drawX, $drawY, 0, 0, $newW, $newH, $srcW, $srcH);
    imagedestroy($srcImg);
}

// 修改drawImageCustom函数：支持绘制到父画布（允许超出子画布范围）
function drawImageCustom($dstCanvas, $imgPath, $dstX, $dstY, $baseW, $baseH, $scale=1.0, $offsetX=0, $offsetY=0, $isParentCanvas = false) {
    if (!file_exists($imgPath)) return;
    $srcImg = imagecreatefrompng($imgPath);
    imagesavealpha($srcImg, true);
    imagealphablending($srcImg, true);
    $srcW = imagesx($srcImg);
    $srcH = imagesy($srcImg);
    
    $targetW = $baseW * $scale;
    $targetH = $baseH * $scale;
    $scaleRatio = min($targetW / $srcW, $targetH / $srcH);
    $newW = $srcW * $scaleRatio;
    $newH = $srcH * $scaleRatio;
    
    if ($isParentCanvas) {
        // 直接绘制到父画布（背景画布），允许超出卡片范围
        $drawX = $dstX + ($baseW - $newW) / 2 + $offsetX;
        $drawY = $dstY + ($baseH - $newH) / 2 + $offsetY;
    } else {
        // 原有逻辑：绘制到子画布（卡片画布）
        $drawX = ($baseW - $newW) / 2 + $offsetX;
        $drawY = ($baseH - $newH) / 2 + $offsetY;
    }
    
    imagecopyresampled($dstCanvas, $srcImg, $drawX, $drawY, 0, 0, $newW, $newH, $srcW, $srcH);
    imagedestroy($srcImg);
}

function drawRoleImage($dstCanvas, $imgPath, $cardW, $cardH) {
    if (!file_exists($imgPath)) return;
    $srcImg = imagecreatefrompng($imgPath);
    imagesavealpha($srcImg, true);
    imagealphablending($srcImg, true);
    $srcW = imagesx($srcImg);
    $srcH = imagesy($srcImg);
    $roleW = 100;
    $roleH = 260;
    $dstX = ($cardW - $roleW) / 2;
    $dstY = ($cardH - $roleH) / 2;
    $scale = max($roleW / $srcW, $roleH / $srcH);
    $cropW = $roleW / $scale;
    $cropH = $roleH / $scale;
    $cropX = ($srcW - $cropW) / 2;
    $cropY = ($srcH - $cropH) / 2;
    imagecopyresampled($dstCanvas, $srcImg, $dstX, $dstY, $cropX, $cropY, $roleW, $roleH, $cropW, $cropH);
    imagedestroy($srcImg);
}

// 新增：绘制多颗横向排列的星星
function drawMultiStars($dstCanvas, $starIconPath, $starCount, $baseX, $baseY, $singleW, $singleH, $scale=1.0, $spacing=2, $align='right', $offsetX=0, $offsetY=0) {
    if (!file_exists($starIconPath) || $starCount < 1) return;
    
    // 计算缩放后的单星尺寸
    $scaledW = $singleW * $scale;
    $scaledH = $singleH * $scale;
    
    // 计算星星组总宽度
    $totalWidth = $starCount * $scaledW + ($starCount - 1) * $spacing;
    
    // 根据对齐方式计算起始X坐标
    switch ($align) {
        case 'left':
            $startX = $baseX + $offsetX;
            break;
        case 'center':
            $startX = $baseX - $totalWidth/2 + $offsetX;
            break;
        case 'right':
            $startX = $baseX - $totalWidth + $offsetX;
            break;
        default:
            $startX = $baseX - $totalWidth + $offsetX;
    }
    $startY = $baseY + $offsetY;
    
    // 循环绘制每颗星星
    for ($i = 0; $i < $starCount; $i++) {
        $x = $startX + $i * ($scaledW + $spacing);
        $y = $startY;
        
        $srcImg = imagecreatefrompng($starIconPath);
        imagesavealpha($srcImg, true);
        imagealphablending($srcImg, true);
        imagecopyresampled(
            $dstCanvas, $srcImg,
            $x, $y, 0, 0,
            $scaledW, $scaledH,
            imagesx($srcImg), imagesy($srcImg)
        );
        imagedestroy($srcImg);
    }
}

// 新增：绘制UP角色角标
function drawUpBadge($dstCanvas, $config, $baseX, $baseY) {
    if (!file_exists($config['path'])) return;
    
    $srcImg = imagecreatefrompng($config['path']);
    imagesavealpha($srcImg, true);
    imagealphablending($srcImg, true);
    
    // 计算缩放后的尺寸
    $srcW = imagesx($srcImg);
    $srcH = imagesy($srcImg);
    $scale = $config['scale'];
    $drawW = $config['width'] * $scale;
    $drawH = $config['height'] * $scale;
    
    // 计算最终绘制位置（左上角）
    $drawX = $baseX + $config['offset_x'];
    $drawY = $baseY + $config['offset_y'];
    
    // 绘制角标
    imagecopyresampled(
        $dstCanvas, $srcImg,
        $drawX, $drawY, 0, 0,
        $drawW, $drawH,
        $srcW, $srcH
    );
    
    imagedestroy($srcImg);
}

// ===================== 新增：绘制NEW图标 =====================
function drawNewBadge($dstCanvas, $config, $baseX, $baseY) {
    if (!file_exists($config['path'])) return;
    
    $srcImg = imagecreatefrompng($config['path']);
    imagesavealpha($srcImg, true);
    imagealphablending($srcImg, true);
    
    // 计算缩放后的尺寸
    $srcW = imagesx($srcImg);
    $srcH = imagesy($srcImg);
    $scale = $config['scale'];
    $drawW = $config['width'] * $scale;
    $drawH = $config['height'] * $scale;
    
    // 计算最终绘制位置（右上角）
    $drawX = $baseX + ($card_w - $drawW) - $config['offset_x'];
    $drawY = $baseY + $config['offset_y'];
    
    // 绘制NEW图标
    imagecopyresampled(
        $dstCanvas, $srcImg,
        $drawX, $drawY, 0, 0,
        $drawW, $drawH,
        $srcW, $srcH
    );
    
    imagedestroy($srcImg);
}

// ===================== 新增：绘制次数图标（1-5） =====================
function drawCountBadge($dstCanvas, $config, $baseX, $baseY, $count) {
    $imgPath = $config['path_prefix'] . $count . $config['path_suffix'];
    if (!file_exists($imgPath)) return;
    
    $srcImg = imagecreatefrompng($imgPath);
    imagesavealpha($srcImg, true);
    imagealphablending($srcImg, true);
    
    // 计算缩放后的尺寸
    $srcW = imagesx($srcImg);
    $srcH = imagesy($srcImg);
    $scale = $config['scale'];
    $drawW = $config['width'] * $scale;
    $drawH = $config['height'] * $scale;
    
    // 计算最终绘制位置（底部居中），新增X偏移
    $drawX = $baseX + ($card_w - $drawW) / 2 + $config['offset_x'];
    $drawY = $baseY + $card_h - $drawH + $config['offset_y'];
    
    // 绘制次数图标
    imagecopyresampled(
        $dstCanvas, $srcImg,
        $drawX, $drawY, 0, 0,
        $drawW, $drawH,
        $srcW, $srcH
    );
    
    imagedestroy($srcImg);
}

// ===================== 新增：绘制金线图标 =====================
function drawGoldLineBadge($dstCanvas, $config, $baseX, $baseY) {
    if (!file_exists($config['path'])) return;
    
    $srcImg = imagecreatefrompng($config['path']);
    imagesavealpha($srcImg, true);
    imagealphablending($srcImg, true);
    
    // 计算缩放后的尺寸
    $srcW = imagesx($srcImg);
    $srcH = imagesy($srcImg);
    $scale = $config['scale'];
    $drawW = $config['width'] * $scale;
    $drawH = $config['height'] * $scale;
    
    // 计算最终绘制位置（底部居中），新增X偏移
    $drawX = $baseX + ($card_w - $drawW) / 2 + $config['offset_x'];
    $drawY = $baseY + $card_h - $drawH + $config['offset_y'];
    
    // 绘制金线图标
    imagecopyresampled(
        $dstCanvas, $srcImg,
        $drawX, $drawY, 0, 0,
        $drawW, $drawH,
        $srcW, $srcH
    );
    
    imagedestroy($srcImg);
}

// ===================== 新增：绘制用户ID（核心新增函数） =====================
function drawUserId($dstCanvas, $userId, $config, $bgW, $bgH) {
    if (empty($userId) || !file_exists($config['font_path'])) return;
    
    // 准备文字内容
    $text = "UID：{$userId}";
    
    // 获取字体配置
    $fontSize = $config['font_size'];
    $fontColor = $config['font_color'];
    $shadowColor = $config['shadow_color'];
    $shadowOffsetX = $config['shadow_offset_x'];
    $shadowOffsetY = $config['shadow_offset_y'];
    $offsetX = $config['offset_x'];
    $offsetY = $config['offset_y'];
    $hasBg = $config['background'];
    $bgColor = $config['bg_color'];
    $bgPadding = $config['bg_padding'];
    
    // 计算文字尺寸
    $textBox = imagettfbbox($fontSize, 0, $config['font_path'], $text);
    $textW = $textBox[2] - $textBox[0];
    $textH = $textBox[1] - $textBox[7];
    
    // 计算文字绘制位置（右下角）
    $textX = $bgW - $textW - $offsetX;
    $textY = $bgH - $offsetY;
    
    // 1. 绘制背景框（如果开启）
    if ($hasBg) {
        $bgX = $textX - $bgPadding[0];
        $bgY = $textY - $textH - $bgPadding[1];
        $bgW = $textW + $bgPadding[0] * 2;
        $bgH = $textH + $bgPadding[1] * 2;
        
        // 创建背景色（支持透明度）
        $bgColorRes = imagecolorallocatealpha(
            $dstCanvas,
            $bgColor[0], $bgColor[1], $bgColor[2], $bgColor[3]
        );
        imagefilledrectangle($dstCanvas, $bgX, $bgY, $bgX + $bgW, $bgY + $bgH, $bgColorRes);
    }
    
    // 2. 绘制文字阴影
    $shadowColorRes = imagecolorallocate($dstCanvas, $shadowColor[0], $shadowColor[1], $shadowColor[2]);
    imagettftext(
        $dstCanvas, $fontSize, 0,
        $textX + $shadowOffsetX, $textY + $shadowOffsetY,
        $shadowColorRes, $config['font_path'], $text
    );
    
    // 3. 绘制主文字
    $fontColorRes = imagecolorallocate($dstCanvas, $fontColor[0], $fontColor[1], $fontColor[2]);
    imagettftext(
        $dstCanvas, $fontSize, 0,
        $textX, $textY,
        $fontColorRes, $config['font_path'], $text
    );
}

// ===================== 加载角色池 =====================
$rolePool = [];
foreach(['star3','star4','star5'] as $star){
    $rolePool[$star] = [];
    foreach($attrList as $attr){
        $rolePool[$star][$attr] = getPngFiles("img/role/$star/$attr");
    }
}

// ===================== 抽卡函数 =====================
function getOneCard($prob, $attrList, $rolePool, $upRole, $upProb, $other5StarProb, $forceStar = null){
    // 新增：标记是否为UP角色
    $isUpRole = false;
    
    if($forceStar && in_array($forceStar, ['star3','star4','star5'])){
        $star = $forceStar;
        
        // 强制五星时，直接返回UP角色
        if($star == 'star5' && !empty(getUpRoleFullPath($upRole, $attrList))){
            $upInfo = getUpRoleFullPath($upRole, $attrList);
            $attr = $upInfo['attr'];
            $roleFile = $upInfo['path'];
            $isUpRole = true;
            return ['star' => $star, 'attr' => $attr, 'img' => $roleFile, 'is_up' => $isUpRole];
        }
    } else {
        $rand = mt_rand(1,1000);
        $total5Star = $prob['star5'] * 10;

        // ============== 修复：UP角色判定逻辑 ==============
        $upInfo = getUpRoleFullPath($upRole, $attrList);
        if(!empty($upInfo) && $rand <= $upProb * 10){
            $star = $upRole['star'];
            $attr = $upInfo['attr'];
            $roleFile = $upInfo['path'];
            $isUpRole = true;
        } elseif($rand <= ($upProb + $other5StarProb) * 10){
            $star = 'star5';
            $filteredRolePool = [];
            foreach($attrList as $a){
                $files = [];
                foreach($rolePool[$star][$a] as $file){
                    if(basename($file) != basename($upRole['file'])){
                        $files[] = $file;
                    }
                }
                if(!empty($files)) $filteredRolePool[$a] = $files;
            }
            if(empty($filteredRolePool)) $filteredRolePool = $rolePool[$star];
            $attr = array_rand($filteredRolePool);
            $roleFile = $filteredRolePool[$attr][array_rand($filteredRolePool[$attr])];
        } elseif($rand <= $total5Star + $prob['star4'] * 10){
            $star = 'star4';
            $attr = $attrList[array_rand($attrList)];
            $imgs = $rolePool[$star][$attr];
            if(empty($imgs)) foreach($attrList as $a){ if(!empty($rolePool[$star][$a])){ $imgs = $rolePool[$star][$a]; $attr = $a; break; } }
            $roleFile = $imgs[array_rand($imgs)];
        } else {
            $star = 'star3';
            $attr = $attrList[array_rand($attrList)];
            $imgs = $rolePool[$star][$attr];
            if(empty($imgs)) foreach($attrList as $a){ if(!empty($rolePool[$star][$a])){ $imgs = $rolePool[$star][$a]; $attr = $a; break; } }
            $roleFile = $imgs[array_rand($imgs)];
        }
        return ['star' => $star, 'attr' => $attr, 'img' => $roleFile, 'is_up' => $isUpRole];
    }

    $attr = $attrList[array_rand($attrList)];
    $imgs = $rolePool[$star][$attr];
    if(empty($imgs)) foreach($attrList as $a){ if(!empty($rolePool[$star][$a])){ $imgs = $rolePool[$star][$a]; $attr = $a; break; } }
    return ['star' => $star, 'attr' => $attr, 'img' => $imgs[array_rand($imgs)], 'is_up' => false];
}

// ===================== 十连 + 保底 =====================
$tenCards = [];
$has5Star = false;

// 先抽10张
for ($i=0; $i<10; $i++) {
    $card = getOneCard($prob, $attrList, $rolePool, $upRole, $upProb, $other5StarProb);
    $tenCards[] = $card;
    if ($card['star'] === 'star5') $has5Star = true;
}

// ===================== 100抽必出五星（核心） =====================
if (!empty($user_id)) {
    $remaining = $max_draw - $counter[$user_id];
    if ($remaining <= 10 && !$has5Star) {
        $pos = mt_rand(0,9);
        // 修复：保底必出UP角色
        $card = getOneCard($prob, $attrList, $rolePool, $upRole, $upProb, $other5StarProb, 'star5');
        $tenCards[$pos] = $card;
        $has5Star = true;
    }
}

// 十连保底4星
$hasHighStar = false;
foreach ($tenCards as $c) {
    if ($c['star'] === 'star4' || $c['star'] === 'star5') { $hasHighStar = true; break; }
}
if (!$hasHighStar) {
    $tenCards[mt_rand(0,9)] = getOneCard($prob, $attrList, $rolePool, $upRole, $upProb, $other5StarProb, 'star4');
}

// 更新计数
if (!empty($user_id)) {
    $counter[$user_id] += 10;
    if ($has5Star) {
        $counter[$user_id] = 0;
    }
    file_put_contents($counter_file, json_encode($counter, JSON_PRETTY_PRINT));
    
    // ===================== 新增：更新用户抽卡数据 =====================
    foreach ($tenCards as $card) {
        updateUserDrawData($user_id, $user_data_dir, $card['img']);
    }
}

// ===================== 画图 =====================
$canvas = imagecreatetruecolor($bg_w, $bg_h);
imagealphablending($canvas, true);
imagesavealpha($canvas, true);

$bgImg = imagecreatefrompng($bg_base);
imagecopyresampled($canvas, $bgImg, 0,0,0,0, $bg_w, $bg_h, imagesx($bgImg), imagesy($bgImg));
imagedestroy($bgImg);

$allWidth = 10 * $card_w + 9 * $gap;
$startX = ($bg_w - $allWidth) / 2;
$startY = ($bg_h - $card_h) / 2;

// ===================== 绘制top_star_frame到最底层（原有逻辑保留） =====================
$idx=0;
foreach($tenCards as $card){
    $star = $card['star'];
    $x = $startX + $idx * ($card_w + $gap);
    $y = $startY;

    // 获取当前星级的配置
    $config = $top_border_config[$star];
    $scale = $config['scale'];
    $border1_offset_y = $config['border1_offset_y'];
    $border2_offset_y = $config['border2_offset_y'];

    // 计算边框绘制位置
    $newW = $card_w * $scale;
    $newH = $card_h * $scale;
    $offX = ($card_w - $newW)/2;

    // 绘制第一个顶层边框（现在在最底层）
    drawImageNoStretch($canvas, $top_star_frame[$star], $x+$offX, $y+$border1_offset_y, $newW, $newH);
    // 绘制第二个顶层边框（现在在最底层）
    drawImageNoStretch($canvas, $top_star_frame2[$star], $x+$offX, $y+$border2_offset_y, $newW, $newH);
    
    $idx++;
}

// 重置索引，开始绘制卡片
$idx=0;
foreach($tenCards as $card){
    $star = $card['star'];
    $attr = $card['attr'];
    $roleFile = $card['img'];
    $isUpRole = $card['is_up']; // 获取是否为UP角色
    $x = $startX + $idx * ($card_w + $gap);
    $y = $startY;

    $cardCanvas = imagecreatetruecolor($card_w, $card_h);
    imagealphablending($cardCanvas, true);
    imagesavealpha($cardCanvas, true);
    $trans = imagecolorallocatealpha($cardCanvas,0,0,0,127);
    imagefill($cardCanvas,0,0,$trans);

    // 1. 绘制frame1（最底层）
    drawImageNoStretch($cardCanvas, $frame1, 0,0,$card_w,$card_h);
    // 2. 绘制角色图片
    drawRoleImage($cardCanvas, $roleFile, $card_w, $card_h);
    // 3. 绘制frame2
    drawImageNoStretch($cardCanvas, $frame2, 0,0,$card_w,$card_h);
    // 4. 绘制star_frame（星级边框）- 移到frame2之后，frame3之前
    drawImageNoStretch($cardCanvas, $star_frame[$star],0,0,$card_w,$card_h);

    // 属性图标
    $attrIcon = $attr_icon_path.$attr.'.png';
    if(file_exists($attrIcon)){
        switch($attr_icon_v_align){
            case 'center': $iy = ($card_h - $attr_icon_h)/2 + $attr_icon_v_offset; break;
            case 'top': $iy = 10 + $attr_icon_v_offset; break;
            case 'bottom': $iy = $card_h - $attr_icon_h -10 + $attr_icon_v_offset; break;
            default: $iy = ($card_h - $attr_icon_h)/2 + $attr_icon_v_offset;
        }
        $ix = $attr_icon_offset_x;
        drawImageNoStretch($cardCanvas, $attrIcon, $ix, $iy, $attr_icon_w, $attr_icon_h);
    }

    // 星级图标（核心修改：绘制多颗横向星星）
    $starCount = intval(str_replace('star', '', $star)); // 提取星级数字（3/4/5）
    $baseStarX = $card_w - 40; // 星星组基准X坐标
    $baseStarY = $card_h - 40; // 星星组基准Y坐标
    drawMultiStars(
        $cardCanvas, 
        $star_icon, 
        $starCount, 
        $baseStarX, 
        $baseStarY, 
        $single_star_icon_w, 
        $single_star_icon_h, 
        $star_scale, 
        $star_spacing, 
        $star_align, 
        $star_offset_x, 
        $star_offset_y
    );

    // 绘制卡片到背景画布
    imagecopy($canvas, $cardCanvas, $x,$y,0,0,$card_w,$card_h);
    imagedestroy($cardCanvas);

    // ===================== 5. 绘制frame3（最上层） =====================
    drawImageCustom(
        $canvas, 
        $frame3, 
        $x, $y, 
        $card_w, $card_h, 
        $frame3_scale, 
        $frame3_offset_x, 
        $frame3_offset_y, 
        true // 标记为绘制到父画布（背景画布）
    );

    // 绘制UP角色角标（新增核心逻辑）
    if($isUpRole){
        // 角标绘制到背景画布（基于卡片位置）
        drawUpBadge($canvas, $up_badge_config, $x, $y);
    }

    // ===================== 新增：绘制角色获取次数相关图标 =====================
    if (!empty($user_id)) {
        $roleCount = getRoleDrawCount($user_id, $user_data_dir, $roleFile);
        
        // 首次获取：绘制NEW图标
        if ($roleCount == 1) {
            drawNewBadge($canvas, $new_icon_config, $x, $y);
        }
        // 2-6次获取：绘制次数图标
        elseif ($roleCount >= 2 && $roleCount <= 6) {
            drawCountBadge($canvas, $count_icon_config, $x, $y, $roleCount - 1);
        }
        // 7次及以上：绘制金线图标
        elseif ($roleCount >= 7) {
            drawGoldLineBadge($canvas, $gold_line_icon_config, $x, $y);
        }
    }

    $idx++;
}

// ===================== 调用绘制用户ID函数（核心新增调用） =====================
drawUserId($canvas, $user_id, $user_id_config, $bg_w, $bg_h);

imagepng($canvas);
imagedestroy($canvas);
exit;
?>
