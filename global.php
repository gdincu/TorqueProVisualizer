<?php

/**
 * Get number parameter (prevents SQL injection)
 */
function getNum($key, $default = 0) {
    if (!isset($_GET[$key]))
        return ($default);
    $ret = str_replace(",", ".", trim($_GET[$key]));
    if (!is_numeric($ret))
        return ($default);
    return $ret;
}

/**
 * Get string parameter (prevents SQL injection)
 */
function getStr($key, $default = "") {
    global $database;
    if (!isset($_GET[$key]))
        return $default;
    $ret = $_GET[$key];
//    if (get_magic_quotes_gpc())
//        $ret = stripslashes($ret); //json_encode($ret, JSON_HEX_APOS)), true);
//    $ret = $database->encodeValue(addslashes($ret));
    return trim($ret);
}

/**
 * formatHourMin
 */
function formatHourMin($timeSec) {
	$mask = "%2s%1s%2s%1s" ;	
	return sprintf($mask,
	str_pad(intval($timeSec / 3600),2,"0",STR_PAD_LEFT),
	"h",
	str_pad(intval(($timeSec % 3600) / 60),2,"0",STR_PAD_LEFT),
	"m"
	);
}

/**
 * lonToTile
 */
function lonToTile($long, $zoom) {
    return (($long + 180) / 360) * pow(2, $zoom);
}

/**
 * latToTile
 */
function latToTile($lat, $zoom) {
    return (1 - log(tan($lat * pi() / 180) + 1 / cos($lat * pi() / 180)) / pi()) / 2 * pow(2, $zoom);
}

/**
 * lonPerPixel
 */
function lonPerPixel($tileNo, $zoom) {

    $a1 = ($tileNo / pow(2, $zoom) * 360 - 180);
    $a2 = (($tileNo + 1) / pow(2, $zoom) * 360 - 180);
    return abs($a2 - $a1) / 512;
}

/**
 * latPerPixel
 */
function latPerPixel($tileNo, $zoom) {
    $n = pi() * (1 - 2 * $tileNo / pow(2, $zoom));
    $a1 = rad2deg(atan(sinh($n)));
    $n = pi() * (1 - 2 * ($tileNo + 1) / pow(2, $zoom));
    $a2 = rad2deg(atan(sinh($n)));
    return abs($a2 - $a1) / 512;
}

/**
 * fetch title
 */
function fetchTile($url) {

    $cacheTileName = "cache/tile_" . md5($url) . ".jpg";
    if (file_exists($cacheTileName) && filesize($cacheTileName) > 100) {
        return file_get_contents($cacheTileName);
    }

	global $abc;
    $abc++;
    if ($abc >= 3) {
        $abc = 0;
        $url = str_replace("/a.", "/" . chr(97 + $abc), $url);
    }
    usleep(500000); //0.5s
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, "TileProxy/1.0");
    curl_setopt($ch, CURLOPT_URL, $url);
	// Used to ensure that the corect URL are used 
	// if (PHP_SAPI == 'cli') {
        // echo "$url\n";
    // }
    $tile = curl_exec($ch);
    curl_close($ch);
    file_put_contents($cacheTileName, $tile);
    //die($url . "s");

    return $tile;
}
