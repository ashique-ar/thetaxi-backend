<?php
// Allow from any origin
if (isset($_SERVER['HTTP_ORIGIN'])) {
    // Decide if the origin in $_SERVER['HTTP_ORIGIN'] is one
    // you want to allow, and if so:
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');    // cache for 1 day
}

// Access-Control headers are received during OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        // may also be using PUT, PATCH, HEAD etc
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");

    exit(0);
}


require '../common.php';
require '../system/cache/cache.php';
/**
 * Calculates the great-circle distance between two points, with
 * the Vincenty formula.
 *
 * @param float $latitudeFrom
 *        	Latitude of start point in [deg decimal]
 * @param float $longitudeFrom
 *        	Longitude of start point in [deg decimal]
 * @param float $latitudeTo
 *        	Latitude of target point in [deg decimal]
 * @param float $longitudeTo
 *        	Longitude of target point in [deg decimal]
 * @param float $earthRadius
 *        	Mean earth radius in [m]
 * @return float Distance between points in [m] (same as earthRadius)
 */
function vincentyGreatCircleDistance($latitudeFrom, $longitudeFrom, $latitudeTo, $longitudeTo)
{
    $earthRadius = 6371;
    // convert from degrees to radians
    $latFrom = deg2rad($latitudeFrom);
    $lonFrom = deg2rad($longitudeFrom);
    $latTo = deg2rad(floatval($latitudeTo));
    $lonTo = deg2rad(floatval($longitudeTo));

    $lonDelta = $lonTo - $lonFrom;
    $a = pow(cos($latTo) * sin($lonDelta), 2) + pow(cos($latFrom) * sin($latTo) - sin($latFrom) * cos($latTo) * cos($lonDelta), 2);
    $b = sin($latFrom) * sin($latTo) + cos($latFrom) * cos($latTo) * cos($lonDelta);

    $angle = atan2(sqrt($a), $b);
    return $angle * $earthRadius;
}



function getDrivingDistance($lat1, $long1, $lat2, $long2)
{

    $gdist = R::findOrCreate('googledistance', [
        'latone' => $lat1,
        'longone' => $long1,
        'lattwo' => $lat2,
        'longtwo' => $long2
    ]);

    if (!(isset($gdist->distance) && isset($gdist->duration))) {
        //AIzaSyDe9IipfEPIfgLHcq_eBsJBxS8ED04i8qs
        //AIzaSyApAvvt7AD-C8c3ySYvI9QILqR4isREJ3s
        //AIzaSyBvrkHw4z2baebi1rRP0P7KvLiwERNAyjA
        $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=" . $lat1 . "," . $long1 . "&destinations=" . $lat2 . "," . $long2 . "&mode=driving&key=AIzaSyCznzr9vdiHj812MY99eDQsHOt8PeKSQCg";

        /*
         * curl_setopt($ch, CURLOPT_URL, $url);
         * curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
         * curl_setopt($ch, CURLOPT_PROXYPORT, 3128);
         * curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
         * curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
         */
        $response = file_get_contents($url);

        // echo $response;

        $response_a = json_decode($response, true);
        $dist = $response_a['rows'][0]['elements'][0]['distance']['text'];
        $time = $response_a['rows'][0]['elements'][0]['duration']['text'];

        $gdist->distance = $dist;
        $gdist->duration = $time;
        $gdist->createdtime = getDateTime();

        R::store($gdist);
    }
    return array(
        'distance' => $gdist->distance,
        'time' => $gdist->duration
    );

}

function getKmMultiplier(float $distance): float
{
    $row = R::getRow("
      SELECT multiplier
        FROM km_rate_multipliers
       WHERE :d >= min_km
         AND :d <  max_km
       LIMIT 1
    ", [':d' => $distance]);

    return isset($row['multiplier'])
        ? floatval($row['multiplier'])
        : 1.0;
}

function calculateKmSegmentedRate(float $distance, float $baseRate): float
{
    $slabs = R::getAll("
        SELECT min_km, max_km, multiplier
        FROM km_rate_multipliers
        ORDER BY min_km
    ");

    $total = 0.0;

    foreach ($slabs as $slab) {
        $min = (float) $slab['min_km'];
        $max = (float) $slab['max_km'];
        $mult = (float) $slab['multiplier'];

        if ($distance <= $min) {
            break;
        }

        $start = $min;
        $end = min($distance, $max);

        $kmInSlab = $end - $start;

        if ($kmInSlab > 0) {
            $total += $kmInSlab * $baseRate * $mult;
        }
    }

    return $total;
}

function parseDistance(string $rawDistance): float
{
    $distanceStr = trim(strtolower(str_replace(' ', '', $rawDistance)));

    if (preg_match('/^([\d.]+)km$/', $distanceStr, $matches)) {
        return floatval($matches[1]);
    } elseif (preg_match('/^([\d.]+)m$/', $distanceStr, $matches)) {
        $meters = floatval($matches[1]);
        if ($meters < 500) {
            return 0;
        } else {
            return 1;
        }
    }

    return 0;
}


function applyDateAdjustment(
    $baseRate,
    $adjustValue,
    string $adjustType,
): float {

    $base = floatval($baseRate);
    $value = floatval($adjustValue);

    switch ($adjustType) {
        case 'percentage':
            $delta = $base * ($value / 100);
            break;
        case 'fixed':
            $delta = $value;
            break;
        default:
            throw new InvalidArgumentException("Invalid adjustType “{$adjustType}”; must be 'percentage' or 'fixed'.");
    }

    return $delta;
}


function changeCurrency($validated)
{
    global $displayCurrency;
    foreach ($validated as &$it) {
        //print_r($it);
        $it['displayRate'] = number_format((float) ($it['baseRate'] / $displayCurrency['exrate']), 2, '.', '');
        $it['displayCurrency'] = $displayCurrency['code'];

        if (isset($it['extrakm']) && $it['extrakm'] != '') {

            $it['displayextrakm'] = number_format((float) ($it['extrakm'] / $displayCurrency['exrate']), 2, '.', '');
        } else if ($it['extrakm'] == '') {
            $it['displayextrakm'] = number_format(0, 2, '.', '');
        }

        if (isset($it['car']['refundabledeposit']) && '' != $it['car']['refundabledeposit']) {

            $it['displayrefundabledeposit'] = number_format((float) ($it['car']['refundabledeposit'] / $displayCurrency['exrate']), 2, '.', '');
            $it['refundabledeposit'] = number_format((float) ($it['car']['refundabledeposit']), 2, '.', '');
        }
        if (isset($it['car']['weddingdeposit']) && '' != $it['car']['weddingdeposit']) {
            $it['displayweddingdeposit'] = number_format((float) ($it['car']['weddingdeposit'] / $displayCurrency['exrate']), 2, '.', '');
            $it['weddingdeposit'] = number_format((float) ($it['car']['weddingdeposit']), 2, '.', '');
        } else if (isset($it['car']['refundabledeposit']) && '' != $it['car']['refundabledeposit']) {
            $it['displayweddingdeposit'] = number_format((float) ($it['car']['refundabledeposit'] / $displayCurrency['exrate']), 2, '.', '');
            $it['refundabledeposit'] = number_format((float) ($it['car']['refundabledeposit']), 2, '.', '');
        }

        if (isset($it['insurancerate']) && '' != $it['insurancerate']) {

            $it['displayinsurancerate'] = number_format((float) ($it['insurancerate'] / $displayCurrency['exrate']), 2, '.', '');
        }



    }
    return $validated;
}


?>