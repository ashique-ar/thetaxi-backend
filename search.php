<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


require 'common-search.php';
$sessionObject;

$sessionObject = R::findOrCreate('taxisession', ['key' => $_REQUEST['requestid']]);
if (!isset($sessionObject->createdTime)) {
    $sessionObject->createdTime = new DateTime();
}

$headOfficeLat = '6.9187556338924585';
$headOfficeLng = '79.88803557115918';

$response = array();
$response['status'] = 'FALSE';

$response['request'] = $_POST;
$response['sd'] = array();
$response['wd'] = array();
$response['aptfrom'] = array();
$response['aptto'] = array();
$response['to'] = array();
$response['taxi'] = array();
$response['wedding'] = array();

$casonsGreenEnabled = isset($_POST['casons-green-enabled']) && $_POST['casons-green-enabled'] == '1';

if ($_POST['search-type'] == 'SD') {
    $pickupKm = 0;
    $dropOffKM = 0;
    $isgreaterthan1km = 0;
    $customLocation = '-1' == $_POST['sd-pickup-location'];
    $nonStoreLocation = '-1' != $_POST['sd-pickup-location'] && '1' != $_POST['sd-pickup-location'];

    if ($customLocation) {

        $pickupKm = getDrivingDistance($headOfficeLat, $headOfficeLng, $_POST['custom-from-lat'], $_POST['custom-from-lng']);
        $dropOffKM = getDrivingDistance($headOfficeLat, $headOfficeLng, $_POST['custom-to-lat'], $_POST['custom-to-lng']);

        $pickupDistance = floatval(str_replace(['km', 'm'], '', $pickupKm['distance']));
        $dropOffDistance = floatval(str_replace(['km', 'm'], '', $dropOffKM['distance']));

        $pickupDistance = parseDistance($pickupKm['distance']);
        $dropOffDistance = parseDistance($dropOffKM['distance']);

        if ($pickupDistance >= 1 && $pickupDistance < 10) {
            $pickupDistance = 10;
        }
        if ($dropOffDistance >= 1 && $dropOffDistance < 10) {
            $dropOffDistance = 10;
        }

        $response['sd']['pickupKm'] = $pickupDistance;
        $response['sd']['dropOffKm'] = $dropOffDistance;
    } elseif ($nonStoreLocation) {
        $cc = R::getRow("select * from servicelocation where id =  " . $_POST['sd-pickup-location'] . " ");
        $pickupKm = getDrivingDistance($headOfficeLat, $headOfficeLng, $cc['lat'], $cc['lng']);
        $dropOffKM = getDrivingDistance($headOfficeLat, $headOfficeLng, $cc['lat'], $cc['lng']);
        $response['sd']['pickupKm'] = str_replace('km', '', $pickupKm['distance']);
        $response['sd']['dropOffKm'] = str_replace('km', '', $dropOffKM['distance']);
        // $response['sd']['pickupKm'] = 0;
        // $response['sd']['dropOffKm'] = 0;
    } else {
        $response['sd']['pickupKm'] = 0;
        $response['sd']['dropOffKm'] = 0;
    }

    $contolCentre = $customLocation ? 1 : $_POST['sd-pickup-location'];
    $cc = R::getRow("select * from servicelocation where id =  " . $_POST['sd-pickup-location'] . " ");

    $pickupLocation = $customLocation ? $_POST['sd-custom-pickup-location'] : $cc['title'];
    $dropOffLocation = $customLocation ? $_POST['sd-custom-drop-location'] : $cc['title'];

    $begin = new DateTime($_POST['sd-pickup-date']);
    $end = new DateTime($_POST['sd-return-date']);

    $now = strtotime($_POST['sd-pickup-date']); // or your date as well
    $your_date = strtotime($_POST['sd-return-date']);
    $datediff = $your_date - $now;

    $dateDiff = round(($datediff / (60 * 60 * 24)) + 1);
    $response['sd']['numberofdays'] = $dateDiff;

    $interval = DateInterval::createFromDateString('1 day');
    $period = new DatePeriod($begin, $interval, $end);


    // Check if Casons Green is enabled

    // Build vehicle query with Casons Green filtering
    $vehicleQuery = "select v.* ,concat( m.name , ' ' ,  v.model  ) as  text ,  v.model as name  , m.name as make_code  from  casonslkvehiclemodel v ,  casonslkvehiclemake m  where  m.id = v.make ";

    // Add car type filter
    if ($_POST['sd-car-type'] != 0) {
        $vehicleQuery .= " and v.type = " . $_POST['sd-car-type'];
    }

    // Add Casons Green filter if enabled
    if ($casonsGreenEnabled) {
        $vehicleQuery .= " and v.eco_friendly = 1";
    }

    $vehicleModles = R::getAll($vehicleQuery);

    $rateSlab = R::getRow("SELECT * FROM caonslkselfdrivenrateslabs WHERE $dateDiff BETWEEN datecountfrom AND datecountto ORDER BY datecountfrom DESC, datecountto ASC LIMIT 1");
    $response['sdrangesql'] = "SELECT * FROM caonslkselfdrivenrateslabs WHERE $dateDiff BETWEEN datecountfrom AND datecountto ORDER BY datecountfrom DESC, datecountto ASC LIMIT 1";
    // $response['sdrangesql'] = "select * from caonslkselfdrivenrateslabs where datecountfrom <= " . $dateDiff . " and  datecountto >= " . $dateDiff . " order by id  limit 1 ";

    $searchResults = array();

    $format = 'd-m-Y';

    $dayFrom = DateTime::createFromFormat($format, $_POST['sd-pickup-date']);
    $dayFromFormatted = $dayFrom->format('Y-m-d H:i:s');
    $incSQL = "select * from casonslkrateincrease where datefrom <= '" . $dayFromFormatted . "' and dateto >=  '" . $dayFromFormatted . "'  order by persantage limit 1 ";
    $rateIncreaseRow = R::getRow($incSQL);
    $hasRateIncrease = isset($rateIncreaseRow) && isset($rateIncreaseRow['id']);



    if ($nonStoreLocation) {
        $contolCentre = 1;
    }

    foreach ($vehicleModles as &$car) {


        $rateContracts = array();
        $rats = array();
        $dispatchedFrom = R::getRow("select * from servicelocation where id =  " . $contolCentre);

        // DATE_FORMAT(periodfrom, '%Y-%m-%d') as periodfrom , DATE_FORMAT(periodto, '%Y-%m-%d') as periodto
        $q = "select taxi as taxirate ,inquiry_sd_enabled, inquiry_sd_km_limit, sd_discount_from, sd_discount_to, sd_discount, sd_discount_type,sd_delivery_rate, sd_delivery_rate, wd_delivery_rate, sd_trip_rate, sdexcess as excessrate ,  batta as daybatta , insurancerate , eco_discount, eco_friendly_inquiry,  id from   casonslkcarcommonrate where    car  = " . $car['id'] . " and controlcenter = " . $contolCentre . " and enablesd = 'on' order by id  limit 1 ";
        $rateContract = R::getRow($q);
        // print_r(json_encode($rateContract));
        if (!isset($rateContract) || !isset($rateContract['id'])) {
            continue;
        }

        if (($customLocation || $nonStoreLocation) && (empty($rateContract['sd_delivery_rate']) || $rateContract['sd_delivery_rate'] == 0)) {
            continue;
        }
        $sq = "select * from  casonslksdcarrangerate where car = " . $car['id'] . " and  `range` = " . $rateSlab['id'] . "  and controlcenter = " . $contolCentre . "  order by id  limit 1";
        $contractSlab = R::getRow($sq);
        $rateContract['sdrate'] = isset($contractSlab) && isset($contractSlab['rate']) ? $contractSlab['rate'] : 0;
        $rateContract['sdrate_new'] = floatval($rateContract['sdrate']);
        $rateContract['rateSlab_new'] = $rateSlab;
        $rateContract['sql_new'] = $sq;
        if (!isset($rateContract['sdrate']) || $rateContract['sdrate'] == '' || $rateContract['sdrate'] == 0) {
            $sq = "select * from  casonslksdcarrangerate where car = " . $car['id'] . " and  `range` = " . $rateSlab['id'] . "  and controlcenter != " . $contolCentre . "  order by rate desc limit 1";
            $contractSlab = R::getRow($sq);
            $rateContract['sdrate'] = isset($contractSlab) && isset($contractSlab['rate']) ? $contractSlab['rate'] : 0;
            if ($rateContract['sdrate'] != 0) {
                $dispatchedFrom = R::getRow("select * from servicelocation where id =  " . $contractSlab['controlcenter']);

                $q = "select taxi as taxirate , sd_delivery_rate, wd_delivery_rate, sd_trip_rate, sdexcess as excessrate ,  batta as daybatta , insurancerate , eco_discount, eco_friendly_inquiry,   id from   casonslkcarcommonrate where    car  = " . $car['id'] . " and controlcenter = " . $contractSlab['controlcenter'] . " order by id  limit 1 ";
                $rateContractNew = R::getRow($q);

                $rateContract['taxirate'] = $rateContractNew['sd_trip_rate'];
                $rateContract['excessrate'] = $rateContractNew['excessrate'];
                $rateContract['daybatta'] = $rateContractNew['daybatta'];
                $rateContract['insurancerate'] = $rateContractNew['insurancerate'];
                // $rateContract['insurancerate'] = $rateContractNew['insurancerate'] ;
            }
        }
        if (!isset($rateContract['sdrate']) || $rateContract['sdrate'] == '' || $rateContract['sdrate'] == 0) {
            continue;
        }
        $car['typeObject'] = R::getRow("select * from tblavehiclecategories where id = " . $car['type']);

        $rats['ratecontracts'] = $rateContracts;
        $rats['custom-from-lat'] = $_POST['custom-from-lat'];
        $rats['custom-from-lng'] = $_POST['custom-from-lng'];
        $rats['custom-to-lat'] = $_POST['custom-to-lat'];
        $rats['custom-to-lng'] = $_POST['custom-to-lng'];
        $rats['c-pickupKm'] = $pickupKm;
        $rats['c-dropOffKM'] = $dropOffKM;
        $rats['c-sd-pickup-location'] = $_POST['sd-pickup-location'];


        $baseDelivery = isset($rateContract['sd_delivery_rate'])
            ? (float) $rateContract['sd_delivery_rate']
            : 0.0;

        $shouldQuoteDelivery = ($customLocation || $nonStoreLocation) && $baseDelivery > 0.0;

        $rats['cuspickup']       = $customLocation ? 'YES' : ($nonStoreLocation ? 'NO' : 0);
        $rats['PickupDistance']  = $pickupKm      ?? 0; // keep your existing shape
        $rats['DropoffDistance'] = $dropOffKM    ?? 0;
        $rats['cardeleveryrate'] = 0.0;
        $rats['capickuprate']    = 0.0;

        // If we should show delivery now, compute once (no duplication)
        if ($shouldQuoteDelivery) {
            $sdPickupKm  = (float) ($response['sd']['pickupKm']  ?? 0);
            $sdDropoffKm = (float) ($response['sd']['dropOffKm'] ?? 0);

            $rats['capickuprate']    = calculateKmSegmentedRate($sdPickupKm,  $baseDelivery);
            $rats['cardeleveryrate'] = calculateKmSegmentedRate($sdDropoffKm, $baseDelivery);
        }

        // Inquiry logic
        $isInquiryEnabledFlag = (bool) ($rateContract['inquiry_sd_enabled'] ?? false);
        $inquiryLimitKm       = (float) ($rateContract['inquiry_sd_km_limit'] ?? 0);
        $pickupKmVal          = (float) ($response['sd']['pickupKm'] ?? 0);

        // If limit==0 and flag is on → always allow inquiry.
        // Else: allow inquiry only when pickup is OUTSIDE the set radius.
        $withinInquiryRadius  = $inquiryLimitKm > 0 && $pickupKmVal < $inquiryLimitKm;
        $rats['inquiryEnabled'] = ($inquiryLimitKm == 0 && $isInquiryEnabledFlag)
            ? true
            : ($isInquiryEnabledFlag && !$withinInquiryRadius);
        if ($casonsGreenEnabled && isset($rateContract['eco_friendly_inquiry'])) {
            $ecoFriendlyInquiry    = (bool) ($rateContract['eco_friendly_inquiry'] ?? false);
            $rats['inquiryEnabled'] = $ecoFriendlyInquiry;
            $isInquiryEnabledFlag = $ecoFriendlyInquiry;
        }
        // Business rule from your original code:
        // When inquiry is allowed (i.e., outside radius), hide delivery charges in the quote.
        if ($rats['inquiryEnabled']) {
            $rats['cardeleveryrate'] = 0.0;
            $rats['capickuprate']    = 0.0;
        }

        $rats['finaldeliveryrate'] = $rats['cardeleveryrate'] + $rats['capickuprate'];

        $rats['sdrate'] = 0;
        //foreach ( $rats['ratecontracts'] as &$rt){
        $rats['sdrate'] += (floatVal($rateContract['sdrate']) * $dateDiff); // + $rt['daybatta'] ;
        //}

        $rats['totalrate'] = $rats['sdrate'] + $rats['finaldeliveryrate'];
        // $rats['totalrate'] = $rats['sdrate'] + $rats['capickuprate'] + $rats['cardeleveryrate'];

        // Apply Casons Green eco discount if enabled
        $rats['eco_discount_applied'] = 0;
        if ($casonsGreenEnabled && isset($rateContract['eco_discount']) && $rateContract['eco_discount'] > 0) {
            $ecoDiscountAmount = ($rats['totalrate'] * $rateContract['eco_discount']) / 100;
            $rats['eco_discount_applied'] = $ecoDiscountAmount;
            $rats['eco_discount_percentage'] = $rateContract['eco_discount'];
            $rats['totalrate'] = $rats['totalrate'] - $ecoDiscountAmount;
            $rats['pre_eco_discount_total'] = $rats['sdrate'] + $rats['finaldeliveryrate'];
        }

        $base = $rats['totalrate'];


        $applies = false;
        $delta = 0;

        $discount = array();



        if ($rateContract['sd_discount'] && $rateContract['sd_discount_from'] && $rateContract['sd_discount_to']) {
            $from = new DateTime($rateContract['sd_discount_from']);
            $to = new DateTime($rateContract['sd_discount_to']);
            if ($begin && $end && $begin >= $from && $begin <= $to && $end >= $from && $end <= $to) {

                if ($rateContract['sd_discount'] < 0) {
                    $discount['discount'] = $rateContract['sd_discount'];
                    $discount['discount_from'] = $rateContract['sd_discount_from'];
                    $discount['discount_to'] = $rateContract['sd_discount_to'];
                    $discount['discount_type'] = $rateContract['sd_discount_type'] ?? 'percentage';
                }
                $applies = true;
            }

            if ($applies) {
                $delta = applyDateAdjustment(
                    $base,
                    $rateContract['sd_discount'],
                    $rateContract['sd_discount_type']
                );
            }

            $rats['totalrate'] = $base + $delta;

            if ($delta < 0) {
                $rats['delta'] = $delta;
            }
        }

        if ($hasRateIncrease) {
            $rats['beforeup'] = $rats['totalrate'];
            $rats['totalrate'] = $rats['totalrate'] + (($rats['totalrate'] / 100) * $rateIncreaseRow['persantage']);
        }

        $item = array();
        $item['type'] = 'CAR';
        $item['dispatchedFrom'] = $dispatchedFrom;
        $item['searchType'] = 'SD';
        $item['rc'] = $rateContract;
        $item['orisdrate'] = $rateContract['sdrate'];
        $item['insurancerate'] = $rateContract['insurancerate'];


        $item['deposit'] = $car['refundabledeposit'];
        $item['pickupLocation'] = $pickupLocation;
        $item['dropoffLocation'] = $dropOffLocation;
        $item['pickupDate'] = $_POST['sd-pickup-date'];
        $item['pickupTime'] = $_POST['sd-pickup-time'];
        $item['returnDate'] = $_POST['sd-return-date'];
        $item['returnTime'] = $_POST['sd-return-time'];


        $item['car'] = $car;
        $item['id'] = uniqid();
        $item['extrakm'] = $rateContract['excessrate'];
        $item['name'] = $car['name'];
        $item['thumbnail'] = $config_imagebase . $car['thumbnail'];
        $item['baseRate'] = $rats['totalrate'];
        $item['baseCurrency'] = 'LKR';
        $item['displayRate'] = $rats['totalrate'];
        $item['displayCurrency'] = 'LKR';
        $item['breadkDown'] = $rats;
        $item['request'] = $_POST;
        $item['numberofdays'] = $dateDiff;
        $item['freemilage'] = $dateDiff * 100;
        $item['discount'] = $discount;
        if ($rateContract['sdrate'] != 0) {
            $searchResults[] = $item;
        }
    }
    $response['rawrss'] = $searchResults;
    $response['results'] = $searchResults;
    $response['status'] = 'TRUE';
}


if ($_POST['search-type'] == 'WEDDING') {

    $pickupKm = 0;
    $dropOffKM = 0;
    $customLocation = '-1' == $_POST['wedding-pickup-location'];
    $response['wedding']['pickupKm'] = 0;
    if ($customLocation) {
        $pickupKm = getDrivingDistance($headOfficeLat, $headOfficeLng, $_POST['custom-from-lat'], $_POST['custom-from-lng']);

        $response['wedding']['pickupKm'] = str_replace('km', '', $pickupKm['distance']);
    }

    $contolCentre = $customLocation ? 1 : $_POST['wedding-pickup-location'];
    $cc = R::getRow("select * from servicelocation where id =  " . $_POST['wedding-pickup-location'] . " ");

    $pickupLocation = $customLocation ? $_POST['wedding-custom-pickup-location'] : $cc['title'];


    $begin = new DateTime($_POST['wedding-pickup-date']);


    $now = strtotime($_POST['wedding-pickup-date']); // or your date as well

    $datediff = 1;


    $response['wedding']['numberofdays'] = $datediff;

    $interval = DateInterval::createFromDateString('1 day');



    // Build vehicle query with optional green filtering
    $vehicleQuery = "select v.* ,concat( m.name , ' ' ,  v.model  ) as  text ,  v.model as name  , m.name as make_code  from  casonslkvehiclemodel v ,  casonslkvehiclemake m  where  m.id = v.make";

    // Add green vehicle filter if Casons Green is enabled
    if (isset($_POST['casons-green-enabled']) && $_POST['casons-green-enabled'] == '1') {
        $vehicleQuery .= " AND v.eco_friendly = 1";
    }

    $vehicleModles = R::getAll($vehicleQuery);

    $searchResults = array();

    foreach ($vehicleModles as &$car) {

        $spaceFound = false;
        $rateContracts = array();
        $rats = array();

        if (!$spaceFound) {
            // DATE_FORMAT(periodfrom, '%Y-%m-%d') as periodfrom , DATE_FORMAT(periodto, '%Y-%m-%d') as periodto
            $q = "select taxi as taxirate ,inquiry_enabled, inquiry_km_limit, sd_delivery_rate, wd_delivery_rate, sd_trip_rate,weddingrate ,  excess as excessrate ,  batta as daybatta , insurancerate , eco_discount, eco_friendly_inquiry,  id from   casonslkcarcommonrate where    car  = " . $car['id'] . " and controlcenter = " . $contolCentre . "  and enablewedding = 'on' order by id  limit 1  ";

            $rateContract = R::getRow($q);
            //               /  print_r($rateContract);
            $rateContracts[] = $rateContract;
        }



        if ($spaceFound || empty($rateContracts) || !isset($rateContracts[0]['weddingrate']) || $rateContracts[0]['weddingrate'] == 0) {
            continue;
        }

        if ($customLocation && (empty($rateContract['sd_delivery_rate']) || $rateContract['sd_delivery_rate'] == 0)) {
            continue;
        }
        $car['typeObject'] = R::getRow("select * from tblavehiclecategories where id = " . $car['type']);
        $rats['ratecontracts'] = $rateContracts;

        // $rats['cardeleveryrate'] = floatVal($response['wedding']['pickupKm']) * floatVal($rateContracts[0]['wd_delivery_rate']);

        $rats['weddingrate'] = 0;
        foreach ($rats['ratecontracts'] as &$rt) {
            $rats['weddingrate'] += floatVal($rt['weddingrate']); // + $rt['daybatta'] ;
        }
        $item = array();
        $pkgRate = 0;
        if (8 == $_POST['wedding-package-type']) {
            $pkgRate = $rats['weddingrate'];
            // 4 HR 40 KM 
            $item['freemilage'] = 80;
        }
        if (4 == $_POST['wedding-package-type']) {
            $pkgRate = $rats['weddingrate'] * 0.7;
            // 8 HR 80 KM 
            $item['freemilage'] = 40;
        }
        if (12 == $_POST['wedding-package-type']) {
            $pkgRate = $rats['weddingrate'] * 1.7;
            // 4 HR 40 - REMOVE 12 HOURS / EXTRA HOURS FROM RATE SCREEN  / EXTRA TAXI KM 
        }

        $isInquiryEnabled = $rateContract['inquiry_enabled'] ?? false;
        $inquiry_km_limit = $rateContract['inquiry_km_limit'] ?? 0;
        $rats['inquiry_enabled'] = $rateContract['inquiry_enabled'];
        $rats['inquiry_km_limit'] = $inquiry_km_limit;
        if ($response['wedding']['pickupKm'] < $inquiry_km_limit) {
            $rats['cardeleveryrate'] = floatVal($response['wedding']['pickupKm']) * floatVal($rateContract['wd_delivery_rate']);
            // $rats['capickuprate'] = floatVal($response['wd']['dropOffKm']) * floatVal($rateContract['wd_delivery_rate']);
            $rats['inquiryEnabled'] = false;
        } else {
            $rats['cardeleveryrate'] = 0;
            // $rats['capickuprate'] = 0;
            $rats['inquiryEnabled'] = $isInquiryEnabled;
        }

        if ($rateContract['inquiry_enabled'] && $inquiry_km_limit == 0) {
            $rats['inquiryEnabled'] = $rateContract['inquiry_enabled'];
        }
        if ($casonsGreenEnabled && isset($rateContract['eco_friendly_inquiry'])) {
            $ecoFriendlyInquiry    = (bool) ($rateContract['eco_friendly_inquiry'] ?? false);
            $rats['inquiryEnabled'] = $ecoFriendlyInquiry;
            $isInquiryEnabledFlag = $ecoFriendlyInquiry;
        }

        $rats['totalrate'] = $pkgRate + $rats['cardeleveryrate'] + (floatVal($rats['cardeleveryrate']) * 0.6);

        // Apply Casons Green eco discount if enabled
        if (isset($_POST['casons-green-enabled']) && $_POST['casons-green-enabled'] == '1' && isset($rateContract['eco_discount']) && $rateContract['eco_discount'] > 0) {
            $ecoDiscountAmount = $rats['totalrate'] * ($rateContract['eco_discount'] / 100);
            $rats['totalrate'] = $rats['totalrate'] - $ecoDiscountAmount;
            $rats['eco_discount_applied'] = $rateContract['eco_discount'];
            $rats['eco_discount_amount'] = $ecoDiscountAmount;
        }

        // $base = $rats['totalrate'];
        // if (
        //     $dateDiff >= $rateContract['wd_discount_from']
        //     && $dateDiff <= $rateContract['wd_discount_to']
        // ) {
        //     $disc = floatval($rateContract['wd_discount']);
        //     if ($rateContract['wd_discount_type'] === 'percent') {
        //         $adjust = $base * ($disc / 100);
        //     } else {
        //         $adjust = $disc;
        //     }
        //     $rats['totalrate'] = $base + $adjust;
        // } else {
        //     $rats['totalrate'] = $base;
        // }

        $item['type'] = 'CAR';
        $item['searchType'] = 'WEDDING';
        $item['deposit'] = $car['weddingdeposit'];
        $item['pickupLocation'] = $pickupLocation;
        $item['dropoffLocation'] = $pickupLocation;
        $item['pickupDate'] = $_POST['wedding-pickup-date'];
        $item['pickupTime'] = $_POST['wedding-pickup-time'];
        $item['returnDate'] = $_POST['wedding-pickup-date'];
        $item['insurancerate'] = $rateContract['insurancerate'];

        $car['seats'] = $car['seats'] - 1;
        $item['car'] = $car;
        $item['id'] = uniqid();
        $item['extrakm'] = $rats['ratecontracts'][0]['excessrate'];
        $item['name'] = $car['name'];
        $item['thumbnail'] = $config_imagebase . $car['thumbnail'];
        $item['baseRate'] = $rats['totalrate'];
        $item['baseCurrency'] = 'LKR';
        $item['displayRate'] = $rats['totalrate'];
        $item['displayCurrency'] = 'LKR';
        $item['breadkDown'] = $rats;
        $item['request'] = $_POST;
        $item['numberofdays'] = 1;



        $searchResults[] = $item;
    }

    $response['results'] = $searchResults;
    $response['status'] = 'TRUE';

    // var_dump($response['results']);
    // exit;
}

if ($_POST['search-type'] == 'WD') {

    $pickupKm = 0;
    $dropOffKM = 0;
    $customLocation = '-1' == $_POST['wd-pickup-location'];
    $nonStoreLocation = '-1' != $_POST['wd-pickup-location'] && '1' != $_POST['wd-pickup-location'];

    if ($customLocation) {
        $pickupKm = getDrivingDistance($headOfficeLat, $headOfficeLng, $_POST['custom-from-lat'], $_POST['custom-from-lng']);
        $dropOffKM = getDrivingDistance($headOfficeLat, $headOfficeLng, $_POST['custom-to-lat'], $_POST['custom-to-lng']);
        $response['wd']['pickupKm'] = str_replace('km', '', $pickupKm['distance']);
        $response['wd']['dropOffKm'] = str_replace('km', '', $dropOffKM['distance']);
    } elseif ($nonStoreLocation) {

        $cc = R::getRow("select * from servicelocation where id =  " . $_POST['wd-pickup-location'] . " ");
        $pickupKm = getDrivingDistance($headOfficeLat, $headOfficeLng, $cc['lat'], $cc['lng']);
        $dropOffKM = getDrivingDistance($headOfficeLat, $headOfficeLng, $cc['lat'], $cc['lng']);
        $response['wd']['pickupKm'] = str_replace('km', '', $pickupKm['distance']);
        $response['wd']['dropOffKm'] = str_replace('km', '', $dropOffKM['distance']);
        // $response['sd']['pickupKm'] = 0;
        // $response['sd']['dropOffKm'] = 0;
    } else {
        $response['wd']['pickupKm'] = 0;
        $response['wd']['dropOffKm'] = 0;
    }

    $contolCentre = $customLocation ? 1 : $_POST['wd-pickup-location'];

    $cc = R::getRow("select * from servicelocation where id =  " . $_POST['wd-pickup-location'] . " ");

    $pickupLocation = $customLocation ? $_POST['wd-custom-pickup-location'] : $cc['title'];
    $dropOffLocation = $customLocation ? $_POST['wd-custom-drop-location'] : $cc['title'];

    $begin = new DateTime($_POST['wd-pickup-date']);
    $end = new DateTime($_POST['wd-return-date']);

    $now = strtotime($_POST['wd-pickup-date']); // or your date as well
    $your_date = strtotime($_POST['wd-return-date']);
    $datediff = $your_date - $now;

    $dateDiff = round(($datediff / (60 * 60 * 24)) + 1);
    $response['wd']['numberofdays'] = $dateDiff;

    $interval = DateInterval::createFromDateString('1 day');
    $period = new DatePeriod($begin, $interval, $end);

    //
    // Check if Casons Green is enabled for with-driver search
    $casonsGreenEnabled = isset($_POST['casons-green-enabled']) && $_POST['casons-green-enabled'] == '1';

    // Build vehicle query with Casons Green filtering for with-driver
    $vehicleQueryWD = "select v.* ,concat( m.name , ' ' ,  v.model  ) as  text ,  v.model as name  , m.name as make_code  from  casonslkvehiclemodel v ,  casonslkvehiclemake m  where  m.id = v.make ";

    // Add car type filter
    if ($_POST['wd-car-type'] != 0) {
        $vehicleQueryWD .= " and v.type = " . $_POST['wd-car-type'];
    }

    // Add Casons Green filter if enabled
    if ($casonsGreenEnabled) {
        $vehicleQueryWD .= " and v.eco_friendly = 1";
    }

    $vehicleModles = R::getAll($vehicleQueryWD);

    $rateSlab = R::getRow("select * from caonslkselfdrivenrateslabs where datecountfrom <= " . $dateDiff . " and  datecountto >= " . $dateDiff . " order by id  limit 1 ");

    $searchResults = array();

    $format = 'd-m-Y';
    $dayFrom = DateTime::createFromFormat($format, $_POST['wd-pickup-date']);
    $dayFromFormatted = $dayFrom->format('Y-m-d H:i:s');
    $incSQL = "select * from casonslkrateincrease where    datefrom <= '" . $dayFromFormatted . "' and dateto >=  '" . $dayFromFormatted . "'  order by persantage limit 1 ";
    $rateIncreaseRow = R::getRow($incSQL);
    $hasRateIncrease = isset($rateIncreaseRow) && isset($rateIncreaseRow['id']);


    if ($nonStoreLocation) {
        $contolCentre = 1;
    }
    foreach ($vehicleModles as &$car) {


        $rateContracts = array();
        $rats = array();

        // DATE_FORMAT(periodfrom, '%Y-%m-%d') as periodfrom , DATE_FORMAT(periodto, '%Y-%m-%d') as periodto
        $q = "select taxi as taxirate ,inquiry_enabled, inquiry_km_limit, wd_discount_from, wd_discount_to, wd_discount, wd_discount_type, sd_delivery_rate, sd_delivery_rate, wd_delivery_rate, sd_trip_rate,  excess as excessrate ,  batta as daybatta , insurancerate , eco_discount, eco_friendly_inquiry,   id from   casonslkcarcommonrate where    car  = " . $car['id'] . " and controlcenter = " . $contolCentre . " and enablewd = 'on' order by id  limit 1 ";

        $rateContract = R::getRow($q);

        if (!isset($rateContract) || !isset($rateContract['id'])) {
            continue;
        }

        // if (($customLocation || $nonStoreLocation) && (empty($rateContract['wd_delivery_rate']) || $rateContract['wd_delivery_rate'] == 0)) {
        //     continue;
        // }
        $sq = "select * from  casonslkwdcarrangerate where car = " . $car['id'] . " and  `range` = " . $rateSlab['id'] . "  and controlcenter = " . $contolCentre . "  order by id  limit 1";
        // echo $sq ;
        $contractSlab = R::getRow($sq);
        $rateContract['sdrate'] = $contractSlab['rate'];

        $car['typeObject'] = R::getRow("select * from tblavehiclecategories where id = " . $car['type']);
        $rats['ratecontracts'] = $rateContracts;
        $isInquiryEnabled = $rateContract['inquiry_enabled'] ?? false;
        $inquiry_km_limit = $rateContract['inquiry_km_limit'] ?? 0;
        $rats['inquiry_enabled'] = $rateContract['inquiry_enabled'];
        $rats['inquiry_km_limit'] = $inquiry_km_limit;
        if ($isInquiryEnabled && $response['wd']['pickupKm'] < $inquiry_km_limit) {
            if (empty($rateContract['wd_delivery_rate']) || $rateContract['wd_delivery_rate'] == 0) {
                $rats['cardeleveryrate'] = 0;
                $rats['capickuprate'] = 0;
            } else {
                $rats['cardeleveryrate'] = floatVal($response['wd']['pickupKm']) * floatVal($rateContract['wd_delivery_rate']);
                $rats['capickuprate'] = floatVal($response['wd']['dropOffKm']) * floatVal($rateContract['wd_delivery_rate']);
            }
            $rats['inquiryEnabled'] = false;
        } else {
            $rats['cardeleveryrate'] = 0;
            $rats['capickuprate'] = 0;
            $rats['inquiryEnabled'] = $isInquiryEnabled;
        }
        if ($rateContract['inquiry_enabled'] && $inquiry_km_limit == 0) {
            $rats['inquiryEnabled'] = $rateContract['inquiry_enabled'];
        }

        if ($casonsGreenEnabled && isset($rateContract['eco_friendly_inquiry'])) {
            $ecoFriendlyInquiry    = (bool) ($rateContract['eco_friendly_inquiry'] ?? false);
            $rats['inquiryEnabled'] = $ecoFriendlyInquiry;
            $isInquiryEnabledFlag = $ecoFriendlyInquiry;
        }


        $rateContract['wdrate'] = isset($contractSlab) && isset($contractSlab['rate']) ? $contractSlab['rate'] : 0;
        $dispatchedFrom = R::getRow("select * from servicelocation where id =  " . $contolCentre);


        $pkm = $response['wd']['pickupKm'];
        $dkm = $response['wd']['dropOffKm'];

        if (!isset($rateContract['wdrate']) || $rateContract['wdrate'] == '' || $rateContract['wdrate'] == 0) {
            $sq = "select * from  casonslkwdcarrangerate where car = " . $car['id'] . " and  `range` = " . $rateSlab['id'] . "  and controlcenter != " . $contolCentre . "  order by rate desc limit 1";
            $contractSlab = R::getRow($sq);
            $rateContract['wdrate'] = isset($contractSlab) && isset($contractSlab['rate']) ? $contractSlab['rate'] : 0;
            if ($rateContract['wdrate'] != 0) {
                $dispatchedFrom = R::getRow("select * from servicelocation where id =  " . $contractSlab['controlcenter']);

                $q = "select taxi as taxirate ,sd_delivery_rate, wd_delivery_rate, sd_trip_rate,  excess as excessrate ,  batta as daybatta , insurancerate , eco_discount, eco_friendly_inquiry,   id from   casonslkcarcommonrate where    car  = " . $car['id'] . " and controlcenter = " . $contractSlab['controlcenter'] . " order by id  limit 1 ";
                $rateContractNew = R::getRow($q);

                $rateContract['taxirate'] = $rateContractNew['taxirate'];
                $rateContract['excessrate'] = $rateContractNew['excessrate'];
                $rateContract['daybatta'] = $rateContractNew['daybatta'];
                $rateContract['insurancerate'] = $rateContractNew['insurancerate'];
            }
        }
        if (!isset($rateContract['wdrate']) || $rateContract['wdrate'] == '' || $rateContract['wdrate'] == 0) {
            continue;
        }

        $rats['wdrate'] = (floatVal($rateContract['wdrate']) * $dateDiff) + (floatVal($rateContract['daybatta']) * $dateDiff);
        $rats['totalrate'] = $rats['wdrate'] + $rats['capickuprate'] + $rats['cardeleveryrate'];

        // Apply Casons Green eco discount for with-driver if enabled
        $rats['eco_discount_applied'] = 0;
        if ($casonsGreenEnabled && isset($rateContract['eco_discount']) && $rateContract['eco_discount'] > 0) {
            $ecoDiscountAmount = ($rats['totalrate'] * $rateContract['eco_discount']) / 100;
            $rats['eco_discount_applied'] = $ecoDiscountAmount;
            $rats['eco_discount_percentage'] = $rateContract['eco_discount'];
            $rats['totalrate'] = $rats['totalrate'] - $ecoDiscountAmount;
            $rats['pre_eco_discount_total'] = $rats['wdrate'] + $rats['capickuprate'] + $rats['cardeleveryrate'];
        }

        $base = $rats['totalrate'];

        if ($rateContract['wd_discount'] && $rateContract['wd_discount_from'] && $rateContract['wd_discount_to']) {
            $from = new DateTime($rateContract['wd_discount_from']);
            $to = new DateTime($rateContract['wd_discount_to']);
            if ($begin && $end && $begin >= $from && $begin <= $to && $end >= $from && $end <= $to) {

                if ($rateContract['wd_discount'] < 0) {
                    $discount['discount'] = $rateContract['wd_discount'];
                    $discount['discount_from'] = $rateContract['wd_discount_from'];
                    $discount['discount_to'] = $rateContract['wd_discount_to'];
                    $discount['discount_type'] = $rateContract['wd_discount_type'] ?? 'percentage';
                }
                $applies = true;
            }

            if ($applies) {
                $delta = applyDateAdjustment(
                    $base,
                    $rateContract['wd_discount'],
                    $rateContract['wd_discount_type']
                );
            }

            $rats['totalrate'] = $base + $delta;

            if ($delta < 0) {
                $rats['delta'] = $delta;
            }
        }

        if ($hasRateIncrease) {
            $rats['beforeup'] = $rats['totalrate'];
            $rats['totalrate'] = $rats['totalrate'] + (($rats['totalrate'] / 100) * $rateIncreaseRow['persantage']);
        }

        $item = array();
        $item['type'] = 'CAR';

        $item['searchType'] = 'WD';
        $item['dispatchedFrom'] = $dispatchedFrom;
        $item['pickupLocation'] = $pickupLocation;
        $item['dropoffLocation'] = $dropOffLocation;
        $item['pickupDate'] = $_POST['wd-pickup-date'];
        $item['pickupTime'] = $_POST['wd-pickup-time'];
        $item['returnDate'] = $_POST['wd-return-date'];
        $item['returnTime'] = $_POST['wd-return-time'];
        $item['insurancerate'] = $rateContract['insurancerate'];

        $car['seats'] = $car['seats'] - 1;
        $item['car'] = $car;
        $item['id'] = uniqid();
        $item['extrakm'] = $rateContract['excessrate'];
        $item['name'] = $car['name'];
        $item['thumbnail'] = $config_imagebase . $car['thumbnail'];
        $item['baseRate'] = $rats['totalrate'];
        $item['baseCurrency'] = 'LKR';
        $item['displayRate'] = $rats['totalrate'];
        $item['displayCurrency'] = 'LKR';
        $item['breadkDown'] = $rats;
        $item['request'] = $_POST;
        $item['numberofdays'] = $dateDiff;
        $item['freemilage'] = $dateDiff * 100;
        $item['discount'] = $discount;
        $searchResults[] = $item;
    }

    $response['results'] = $searchResults;
    $response['status'] = 'TRUE';
}



if ($_POST['search-type'] == 'APT-FROM') {
    $response = getFromAirportResults($response);
}
if ($_POST['search-type'] == 'APT-TO') {
    $response = getToAirportResults($response);
}


if ($_POST['search-type'] == 'TAXI') {
    $response = getTaxiResults($response);
}
$rs = $response['results'];
$validated = array();
foreach ($rs as &$it) {

    if ($it['baseRate'] > 0) {
        $validated[] = $it;
    }
}


$validated = changeCurrency($validated);

$response['results'] = $validated;
$compressed = base64_encode(gzcompress(json_encode($response), 9));
$curentResults = R::dispense("carresults");
$curentResults->sessionid = $sessionObject->id;
$curentResults->transaction = sprintf($_POST['requestid']);
$curentResults->payload = $compressed;
R::store($curentResults);
$sessionObject->currentresults = $curentResults->id;
R::store($sessionObject);
// var_dump(json_encode($response, JSON_PRETTY_PRINT));
echo json_encode($response, JSON_PRETTY_PRINT);





/**
 * @param response
 * @param searchResults
 */

function getTaxiResults($response)
{
    $pickupKmRaw = 0;
    $returnKmRaw = 0;
    global $config_imagebase;



    $taxiKm = getDrivingDistance($_POST['from-lat'], $_POST['from-lng'], $_POST['to-lat'], $_POST['to-lng']);
    $response['taxi']['distance'] = str_replace('km', '', $taxiKm['distance']);

    $response['tripdistance'] = $taxiKm['distance'];
    $response['triptiume'] = $taxiKm['time'];

    $dateDiff = 1;
    $response['taxi']['numberofdays'] = $dateDiff;



    // Build vehicle query with optional green filtering
    $vehicleQuery = "select v.* ,concat( m.name , ' ' ,  v.model  ) as  text ,  v.model as name  , m.name as make_code  from  casonslkvehiclemodel v ,  casonslkvehiclemake m  where  m.id = v.make";

    $casonsGreenEnabled = isset($_POST['casons-green-enabled']) && $_POST['casons-green-enabled'] == '1' ? true : false;
    // Add green vehicle filter if Casons Green is enabled
    if ($casonsGreenEnabled) {
        $vehicleQuery .= " and v.eco_friendly = 1";
    }

    $vehicleModles = R::getAll($vehicleQuery);

    $contolCentre = R::getRow("select * from servicelocation where title like '%casons%' limit 1 ");


    $pickupKmRaw = getDrivingDistance($contolCentre['lat'], $contolCentre['lng'], $_POST['from-lat'], $_POST['from-lng']);
    $returnKmRaw = getDrivingDistance($contolCentre['lat'], $contolCentre['lng'], $_POST['to-lat'], $_POST['to-lng']);


    $dateDiff = 0;
    if ('Y' == $_POST['taxi-return-wanted']) {


        $now = strtotime($_POST['taxi-from-date']); // or your date as well
        $your_date = strtotime($_POST['taxi-ret-from-date']);
        $datediff = $your_date - $now;

        $dateDiff = round($datediff / (60 * 60 * 24));
    }


    $begin = new DateTime($_POST['taxi-from-date']);
    $end = new DateTime($_POST['taxi-ret-from-date']);

    $searchResults = array();

    $format = 'd-m-Y';
    $dayFrom = DateTime::createFromFormat($format, $_POST['taxi-from-date']);
    $dayFromFormatted = $dayFrom->format('Y-m-d H:i:s');
    $incSQL = "select * from casonslkrateincrease where    datefrom <= '" . $dayFromFormatted . "' and dateto >=  '" . $dayFromFormatted . "'  order by persantage limit 1 ";
    $rateIncreaseRow = R::getRow($incSQL);
    $hasRateIncrease = isset($rateIncreaseRow) && isset($rateIncreaseRow['id']);


    foreach ($vehicleModles as &$car) {


        $rateContracts = array();
        $rats = array();



        // DATE_FORMAT(periodfrom, '%Y-%m-%d') as periodfrom , DATE_FORMAT(periodto, '%Y-%m-%d') as periodto
        $q = "select taxi as taxirate ,inquiry_enabled, inquiry_km_limit, wd_discount, wd_discount_from,wd_discount_type, wd_discount_to, sd_delivery_rate, wd_delivery_rate, sd_trip_rate, del ,  excess as excessrate ,  batta as daybatta , insurancerate , eco_discount, eco_friendly_inquiry,    id from   casonslkcarcommonrate where    car  = " . $car['id'] . " and controlcenter = " . $contolCentre['id'] . " and enabletaxi = 'on' order by id  limit 1  ";

        $rateContract = R::getRow($q);
        if (isset($rateContract) && isset($rateContract['id'])) {
        } else {
            continue;
        }

        if (empty($rateContract['wd_delivery_rate']) || floatval($rateContract['wd_delivery_rate']) === 0.0) {
            continue;
        }

        $rateContracts[] = $rateContract;

        $car['typeObject'] = R::getRow("select * from tblavehiclecategories where id = " . $car['type']);
        $rats['ratecontracts'] = $rateContracts;

        // $rats['cardeleveryrate'] = floatVal($response['taxi']['distance']) * floatVal($rateContracts[0]['wd_delivery_rate']);

        $rats['sdrate'] = 0;

        $pkm = floatval(str_replace('km', '', $pickupKmRaw['distance']));
        $dkm = floatval(str_replace('km', '', $returnKmRaw['distance']));


        $rats['ratefordistance'] = floatval($rateContract['taxirate']) * ($response['taxi']['distance'] + $response['taxi']['distance']);
        // $rats['ratefordistance'] = floatval($rateContract['taxirate']) * floatval($response['taxi']['distance']) * floatval($response['taxi']['distance']);

        $isInquiryEnabled = $rateContract['inquiry_enabled'] ?? false;
        $inquiry_km_limit = $rateContract['inquiry_km_limit'] ?? 0;
        $rats['inquiry_enabled'] = $rateContract['inquiry_enabled'];
        $rats['inquiry_km_limit'] = $inquiry_km_limit;
        if ($pkm < $inquiry_km_limit) {


            $deliveryRate = floatval($rateContracts[0]['wd_delivery_rate']);

            $rats['cardeleveryrate'] = calculateKmSegmentedRate($pkm, $deliveryRate);
            $rats['capickuprate'] = calculateKmSegmentedRate($dkm, $deliveryRate);

            // $deliveryRate = floatval($rateContracts[0]['wd_delivery_rate']);
            // $rats['cardeleveryrate'] = $deliveryRate * $pkm * $pickMult;
            // $rats['capickuprate'] = $deliveryRate * $dkm * $dropMult;
            $rats['inquiryEnabled'] = false;
        } else {
            $rats['cardeleveryrate'] = 0;
            $rats['capickuprate'] = 0;
            $rats['inquiryEnabled'] = $isInquiryEnabled;
        }

        if ($rateContract['inquiry_enabled'] && $inquiry_km_limit == 0) {
            $rats['inquiryEnabled'] = $rateContract['inquiry_enabled'];
        }

        if ($casonsGreenEnabled && isset($rateContract['eco_friendly_inquiry'])) {
            $ecoFriendlyInquiry    = (bool) ($rateContract['eco_friendly_inquiry'] ?? false);
            $rats['inquiryEnabled'] = $ecoFriendlyInquiry;
            $isInquiryEnabledFlag = $ecoFriendlyInquiry;
        }

        $rats['totalrate'] = $rats['ratefordistance'] + $rats['capickuprate'] + $rats['cardeleveryrate'];

        // Apply Casons Green eco discount if enabled
        if ($casonsGreenEnabled && isset($rateContract['eco_discount']) && $rateContract['eco_discount'] > 0) {
            $ecoDiscountAmount = $rats['totalrate'] * ($rateContract['eco_discount'] / 100);
            $rats['totalrate'] = $rats['totalrate'] - $ecoDiscountAmount;
            $rats['eco_discount_applied'] = $rateContract['eco_discount'];
            $rats['eco_discount_amount'] = $ecoDiscountAmount;
        }

        if ('Y' == $_POST['taxi-return-wanted']) {
            $taxiBase = floatval($rateContracts[0]['taxirate']);
            $distance = $response['taxi']['distance'];

            $segmentRate = calculateKmSegmentedRate($distance, $taxiBase);

            // multiply by your day-count or whatever $dateDiff is
            $rats['totalrate'] += $segmentRate * $dateDiff;

            // $rats['totalrate'] = $rats['totalrate'] + (floatVal($rateContracts[0]['taxirate']) * $response['taxi']['distance'] * $dateDiff * getKmMultiplier($response['taxi']['distance']));
            // $rats['totalrate'] = $rats['totalrate'] + (floatVal($rateContracts[0]['taxirate']) * $response['taxi']['distance'] * ($dateDiff > 1 ? 1 : 0.8)) * getKmMultiplier($response['taxi']['distance']);
        }


        $base = $rats['totalrate'];
        $delta = 0;

        if ($rateContract['wd_discount'] && $rateContract['wd_discount_from'] && $rateContract['wd_discount_to']) {
            $from = new DateTime($rateContract['wd_discount_from']);
            $to = new DateTime($rateContract['wd_discount_to']);
            if ($begin && $end && $begin >= $from && $begin <= $to && $end >= $from && $end <= $to) {

                if ($rateContract['wd_discount'] < 0) {
                    $discount['discount'] = $rateContract['wd_discount'];
                    $discount['discount_from'] = $rateContract['wd_discount_from'];
                    $discount['discount_to'] = $rateContract['wd_discount_to'];
                    $discount['discount_type'] = $rateContract['wd_discount_type'] ?? 'percentage';
                }

                $applies = true;
            }

            if ($applies) {
                $delta = applyDateAdjustment(
                    $base,
                    $rateContract['sd_discount'],
                    $rateContract['sd_discount_type']
                );
            }

            $rats['totalrate'] = $base + $delta;

            if ($delta < 0) {
                $rats['delta'] = $delta;
            }
        }

        if ($hasRateIncrease) {
            $rats['beforeup'] = $rats['totalrate'];
            $rats['totalrate'] = $rats['totalrate'] + (($rats['totalrate'] / 100) * $rateIncreaseRow['persantage']);
        }

        $item = array();
        $item['type'] = 'CAR';
        $item['searchType'] = 'TAXI';

        $item['pickupLocation'] = $_POST['from-name'];
        $item['dropoffLocation'] = $_POST['to-name'];


        $item['returnpickupLocation'] = $_POST['return-from-name'];
        $item['returndropoffLocation'] = $_POST['return-to-name'];

        $item['pickupDate'] = $_POST['taxi-from-date'];
        $item['pickupTime'] = $_POST['taxi-from-time'];
        $item['returnDate'] = $_POST['taxi-ret-from-date'];
        $item['returnTime'] = $_POST['taxi-ret-from-time'];
        $item['insurancerate'] = $rateContract['insurancerate'];
        $item['drivingdistance'] = str_replace('km', '', $taxiKm['distance']);
        $item['drivingtime'] = $taxiKm['time'];
        $car['seats'] = $car['seats'] - 1;
        $item['car'] = $car;
        $item['pickuptime'] = $_POST['taxi-from-time'];
        $item['returndate'] = $_POST['taxi-ret-from-date'];
        $item['returntime'] = $_POST['taxi-ret-from-time'];
        $item['pickupkm'] = $pickupKmRaw;
        $item['returnkm'] = $returnKmRaw;
        $item['round-trip'] = $_POST['taxi-return-wanted'];
        $item['id'] = uniqid();
        $item['extrakm'] = $rats['ratecontracts'][0]['excessrate'];
        $item['name'] = $car['name'];
        $item['thumbnail'] = $config_imagebase . $car['thumbnail'];
        $item['baseRate'] = $rats['totalrate'];
        $item['baseCurrency'] = 'LKR';
        $item['displayRate'] = $rats['totalrate'];
        $item['displayCurrency'] = 'LKR';
        $item['breadkDown'] = $rats;
        $item['request'] = $_POST;
        $item['numberofdays'] = $dateDiff;
        $item['freemilage'] = $dateDiff * 100;
        $item['discount'] = $discount;

        if ($item['baseRate'] <= 0)
            continue;
        $searchResults[] = $item;
    }

    $response['results'] = $searchResults;
    $response['status'] = 'TRUE';

    return $response;
}

/**
 * @param response
 * @param searchResults
 */

function getToAirportResults($response)
{
    $pickupKm = 0;
    $returnKM = 0;
    global $config_imagebase;


    $contolCentre = R::getRow("select * from servicelocation where id = " . $_POST['apto-dropoff-location']);
    $pickupKm = getDrivingDistance($contolCentre['lat'], $contolCentre['lng'], $_POST['from-lat'], $_POST['from-lng']);
    $response['aptto']['pickupKm'] = str_replace('km', '', $pickupKm['distance']);

    $response['tripdistance'] = $pickupKm['distance'];
    $response['triptiume'] = $pickupKm['time'];
    $dateDiff = 1;
    $response['aptto']['numberofdays'] = $dateDiff;

    $casonsGreenEnabled = isset($_POST['casons-green-enabled']) && $_POST['casons-green-enabled'] == '1' ? true : false;


    // Build vehicle query with optional green filtering
    $vehicleQuery = "select v.* ,concat( m.name , ' ' ,  v.model  ) as  text ,  v.model as name  , m.name as make_code  from  casonslkvehiclemodel v ,  casonslkvehiclemake m  where  m.id = v.make";
    // Add green vehicle filter if Casons Green is enabled
    if ($casonsGreenEnabled) {
        $vehicleQuery .= " and v.eco_friendly = 1";
    }

    $vehicleModles = R::getAll($vehicleQuery);

    $begin = new DateTime($_POST['apto-pickup-date']);
    $searchResults = array();

    $format = 'd-m-Y';
    $dayFrom = DateTime::createFromFormat($format, $_POST['apto-pickup-date']);
    $dayFromFormatted = $dayFrom->format('Y-m-d H:i:s');
    $incSQL = "select * from casonslkrateincrease where    datefrom <= '" . $dayFromFormatted . "' and dateto >=  '" . $dayFromFormatted . "'  order by persantage limit 1 ";
    $rateIncreaseRow = R::getRow($incSQL);
    $hasRateIncrease = isset($rateIncreaseRow) && isset($rateIncreaseRow['id']);


    foreach ($vehicleModles as &$car) {


        $rateContracts = array();
        $rats = array();
        $q = "";
        if ($contolCentre['id'] == 3) {
            // DATE_FORMAT(periodfrom, '%Y-%m-%d') as periodfrom , DATE_FORMAT(periodto, '%Y-%m-%d') as periodto
            $q = " select taxi as taxirate ,inquiry_enabled, inquiry_km_limit,sd_delivery_rate, wd_delivery_rate, sd_trip_rate,  excess as excessrate ,  batta as daybatta , apt as aprate ,  insurancerate , eco_discount, eco_friendly_inquiry,   id from   casonslkcarcommonrate where  enableapt = 'on' and   car  = " . $car['id'] . " and (  controlcenter = " . $contolCentre['id'] . " or  controlcenter = 1 ) and apt > 0  order by id  limit 1   ";
        } else {
            $q = " select taxi as taxirate ,inquiry_enabled, inquiry_km_limit,sd_delivery_rate, wd_delivery_rate, sd_trip_rate,  excess as excessrate ,  batta as daybatta , apt as aprate ,  insurancerate , eco_discount, eco_friendly_inquiry,   id from   casonslkcarcommonrate where  enableapt = 'on' and   car  = " . $car['id'] . " and controlcenter = " . $contolCentre['id'] . " order by id  limit 1   ";
        }
        $rateContract = R::getRow($q);
        if (isset($rateContract) && isset($rateContract['id'])) {
        } else {


            continue;
        }
        $rateContracts[] = $rateContract;





        $car['typeObject'] = R::getRow("select * from tblavehiclecategories where id = " . $car['type']);
        $rats['ratecontracts'] = $rateContracts;

        $isInquiryEnabled = $rateContract['inquiry_enabled'] ?? false;
        $inquiry_km_limit = $rateContract['inquiry_km_limit'] ?? 0;
        $rats['inquiry_enabled'] = $rateContract['inquiry_enabled'];
        $rats['inquiry_km_limit'] = $inquiry_km_limit;
        if ($response['aptto']['pickupKm'] < $inquiry_km_limit) {
            $rats['cardeleveryrate'] = floatVal($response['aptto']['pickupKm']) * floatVal($rateContract['wd_delivery_rate']);
            // $rats['capickuprate'] = floatVal($response['wd']['dropOffKm']) * floatVal($rateContract['wd_delivery_rate']);
            $rats['inquiryEnabled'] = false;
        } else {
            $rats['cardeleveryrate'] = 0;
            // $rats['capickuprate'] = 0;
            $rats['inquiryEnabled'] = $isInquiryEnabled;
        }
        // $rats['cardeleveryrate'] = floatVal($response['aptto']['pickupKm']) * $rateContracts[0]['aprate'];

        if ($rateContract['inquiry_enabled'] && $inquiry_km_limit == 0) {
            $rats['inquiryEnabled'] = $rateContract['inquiry_enabled'];
        }

        if ($casonsGreenEnabled && isset($rateContract['eco_friendly_inquiry'])) {
            $ecoFriendlyInquiry    = (bool) ($rateContract['eco_friendly_inquiry'] ?? false);
            $rats['inquiryEnabled'] = $ecoFriendlyInquiry;
            $isInquiryEnabledFlag = $ecoFriendlyInquiry;
        }

        $rats['sdrate'] = 0;

        $rats['totalrate'] = floatVal($rateContracts[0]['aprate']) * $response['aptto']['pickupKm']; //+  $response['aptto']['returnkm ']  *  $rats['cardeleveryrate'] ;

        // Apply Casons Green eco discount if enabled
        if ($casonsGreenEnabled && isset($rateContract['eco_discount']) && $rateContract['eco_discount'] > 0) {
            $ecoDiscountAmount = $rats['totalrate'] * ($rateContract['eco_discount'] / 100);
            $rats['totalrate'] = $rats['totalrate'] - $ecoDiscountAmount;
            $rats['eco_discount_applied'] = $rateContract['eco_discount'];
            $rats['eco_discount_amount'] = $ecoDiscountAmount;
        }

        // $base = $rats['totalrate'];
        // if (
        //     $dateDiff >= $rateContract['sd_discount_from']
        //     && $dateDiff <= $rateContract['sd_discount_to']
        // ) {
        //     $disc = floatval($rateContract['sd_discount']);
        //     if ($rateContract['sd_discount_type'] === 'percent') {
        //         $adjust = $base * ($disc / 100);
        //     } else {
        //         $adjust = $disc;
        //     }
        //     $rats['totalrate'] = $base + $adjust;
        // } else {
        //     $rats['totalrate'] = $base;
        // }

        if ($hasRateIncrease) {
            $rats['beforeup'] = $rats['totalrate'];
            $rats['totalrate'] = $rats['totalrate'] + (($rats['totalrate'] / 100) * $rateIncreaseRow['persantage']);
        }

        $item = array();
        $item['type'] = 'CAR';
        $item['searchType'] = 'APT-TO';
        $item['dropoffLocation'] = $contolCentre['title'];
        $item['pickupLocation'] = $_POST['from-name'];
        $item['pickupDate'] = $_POST['apto-pickup-date'];
        $item['pickupTime'] = $_POST['apto-pickup-time'];
        $item['insurancerate'] = $rateContract['insurancerate'];
        $item['drivingdistance'] = str_replace('km', '', $pickupKm['distance']);
        $item['drivingtime'] = $pickupKm['time'];
        $car['seats'] = $car['seats'] - 1;
        $item['car'] = $car;
        $item['pickuptime'] = $_POST['apto-pickup-time'];
        $item['id'] = uniqid();
        $item['extrakm'] = $rats['ratecontracts'][0]['excessrate'];
        $item['name'] = $car['name'];
        $item['thumbnail'] = $config_imagebase . $car['thumbnail'];
        $item['baseRate'] = $rats['totalrate'];
        $item['baseCurrency'] = 'LKR';
        $item['displayRate'] = $rats['totalrate'];
        $item['displayCurrency'] = 'LKR';
        $item['breadkDown'] = $rats;
        $item['request'] = $_POST;
        $item['numberofdays'] = $dateDiff;
        $item['freemilage'] = $dateDiff * 100;

        if ($item['baseRate'] <= 0)
            continue;
        $searchResults[] = $item;
    }

    $response['results'] = $searchResults;
    $response['status'] = 'TRUE';

    return $response;
}





/**
 * @param response
 * @param searchResults
 */

function getFromAirportResults($response)
{
    $pickupKm = 0;

    global $config_imagebase;


    $contolCentre = R::getRow("select * from servicelocation where id = " . $_POST['apfrom-pickup-location']);
    $pickupKm = getDrivingDistance($contolCentre['lat'], $contolCentre['lng'], $_POST['from-lat'], $_POST['from-lng']);
    $response['aptfrom']['pickupKm'] = str_replace('km', '', $pickupKm['distance']);
    $response['tripdistance'] = $pickupKm['distance'];
    $response['triptiume'] = $pickupKm['time'];

    $dateDiff = 1;
    $response['aptfrom']['numberofdays'] = $dateDiff;
    $casonsGreenEnabled = isset($_POST['casons-green-enabled']) && $_POST['casons-green-enabled'] == '1' ? true : false;

    // Build vehicle query with optional green filtering
    $vehicleQuery = "select v.* ,concat( m.name , ' ' ,  v.model  ) as  text ,  v.model as name  , m.name as make_code  from  casonslkvehiclemodel v ,  casonslkvehiclemake m  where  m.id = v.make";
    // Add green vehicle filter if Casons Green is enabled
    if ($casonsGreenEnabled) {
        $vehicleQuery .= " and v.eco_friendly = 1";
    }
    $vehicleModles = R::getAll($vehicleQuery);

    $begin = new DateTime($_POST['apfrom-pickup-date']);
    $searchResults = array();

    $format = 'd-m-Y';
    $dayFrom = DateTime::createFromFormat($format, $_POST['apfrom-pickup-date']);
    $dayFromFormatted = $dayFrom->format('Y-m-d H:i:s');
    $incSQL = "select * from casonslkrateincrease where    datefrom <= '" . $dayFromFormatted . "' and dateto >=  '" . $dayFromFormatted . "'  order by persantage limit 1 ";
    $rateIncreaseRow = R::getRow($incSQL);
    $hasRateIncrease = isset($rateIncreaseRow) && isset($rateIncreaseRow['id']);


    $dispatchedFrom = $contolCentre;

    foreach ($vehicleModles as &$car) {
        $rats = array();

        $q = "";
        // DATE_FORMAT(periodfrom, '%Y-%m-%d') as periodfrom , DATE_FORMAT(periodto, '%Y-%m-%d') as periodto
        if ($contolCentre['id'] == 3) {
            $q = " select taxi as taxirate , inquiry_enabled, inquiry_km_limit,sd_delivery_rate, wd_delivery_rate, sd_trip_rate, excess as excessrate ,  batta as daybatta , apt as aprate , insurancerate , eco_discount, eco_friendly_inquiry,    id from   casonslkcarcommonrate where enableapt = 'on' and    car  = " . $car['id'] . " and  ( controlcenter = " . $contolCentre['id'] . " or controlcenter = 1 ) and apt > 0  order by id  limit 1 ";
        } else {
            $q = " select taxi as taxirate ,inquiry_enabled, inquiry_km_limit,sd_delivery_rate, wd_delivery_rate, sd_trip_rate,  excess as excessrate ,  batta as daybatta , apt as aprate ,  insurancerate , eco_discount, eco_friendly_inquiry,   id from   casonslkcarcommonrate where  enableapt = 'on' and    car  = " . $car['id'] . " and    controlcenter = " . $contolCentre['id'] . "   and apt > 0  order by id  limit 1 ";
        }


        $rateContract = R::getRow($q);
        // echo $q ;
        if (isset($rateContract) && isset($rateContract['id'])) {
        } else {
            continue;
        }

        if ($rateContract['aprate'] == 0) {
            $sq = "select taxi as taxirate , sd_delivery_rate, wd_delivery_rate, sd_trip_rate, excess as excessrate ,  batta as daybatta , apt as aprate ,  insurancerate , eco_discount, eco_friendly_inquiry,   id from   casonslkcarcommonrate where  car  = " . $car['id'] . " and  controlcenter != " . $contolCentre['id'] . "  order by aprate desc limit 1";
            $rateContract = R::getRow($q);

            if (isset($rateContract) && isset($rateContract['id']) && $rateContract['aprate'] != 0) {
                $cc = R::getRow("select * from servicelocation where id =  " . $contractSlab['controlcenter']);
                $dispatchedFrom = $cc;
                $pickupKm = getDrivingDistance($cc['lat'], $cc['lng'], $_POST['from-lat'], $_POST['from-lng']);

                $response['aptfrom']['pickupKm'] = str_replace('km', '', $pickupKm['distance']);
            }
        }

        $car['typeObject'] = R::getRow("select * from tblavehiclecategories where id = " . $car['type']);

        $isInquiryEnabled = $rateContract['inquiry_enabled'] ?? false;
        $inquiry_km_limit = $rateContract['inquiry_km_limit'] ?? 0;
        $rats['inquiry_enabled'] = $rateContract['inquiry_enabled'];
        $rats['inquiry_km_limit'] = $inquiry_km_limit;
        if ($response['aptfrom']['pickupKm'] < $inquiry_km_limit) {
            $rats['cardeleveryrate'] = floatVal($response['aptfrom']['pickupKm']) * floatVal($rateContract['wd_delivery_rate']);
            // $rats['capickuprate'] = floatVal($response['wd']['dropOffKm']) * floatVal($rateContract['wd_delivery_rate']);
            $rats['inquiryEnabled'] = false;
        } else {
            $rats['cardeleveryrate'] = 0;
            // $rats['capickuprate'] = 0;
            $rats['inquiryEnabled'] = $isInquiryEnabled;
        }

        if ($rateContract['inquiry_enabled'] && $inquiry_km_limit == 0) {
            $rats['inquiryEnabled'] = $rateContract['inquiry_enabled'];
        }

        if ($casonsGreenEnabled && isset($rateContract['eco_friendly_inquiry'])) {
            $ecoFriendlyInquiry    = (bool) ($rateContract['eco_friendly_inquiry'] ?? false);
            $rats['inquiryEnabled'] = $ecoFriendlyInquiry;
            $isInquiryEnabledFlag = $ecoFriendlyInquiry;
        }

        // $rats['cardeleveryrate'] = floatVal($response['aptfrom']['pickupKm']) * floatVal($rateContract['aprate']);

        $rats['sdrate'] = 0;

        $rats['totalrate'] = floatVal($rateContract['aprate']) * floatVal($response['aptfrom']['pickupKm']);

        // Apply Casons Green eco discount if enabled
        if ($casonsGreenEnabled && isset($rateContract['eco_discount']) && $rateContract['eco_discount'] > 0) {
            $ecoDiscountAmount = $rats['totalrate'] * ($rateContract['eco_discount'] / 100);
            $rats['totalrate'] = $rats['totalrate'] - $ecoDiscountAmount;
            $rats['eco_discount_applied'] = $rateContract['eco_discount'];
            $rats['eco_discount_amount'] = $ecoDiscountAmount;
        }

        // $base = $rats['totalrate'];
        // if (
        //     $dateDiff >= $rateContract['wd_discount_from']
        //     && $dateDiff <= $rateContract['wd_discount_to']
        // ) {
        //     $disc = floatval($rateContract['wd_discount']);
        //     if ($rateContract['wd_discount_type'] === 'percent') {
        //         $adjust = $base * ($disc / 100);
        //     } else {
        //         $adjust = $disc;
        //     }
        //     $rats['totalrate'] = $base + $adjust;
        // } else {
        //     $rats['totalrate'] = $base;
        // }
        if ($hasRateIncrease) {
            $rats['beforeup'] = $rats['totalrate'];
            $rats['totalrate'] = $rats['totalrate'] + (($rats['totalrate'] / 100) * $rateIncreaseRow['persantage']);
        }

        $item = array();
        $item['type'] = 'CAR';
        $item['searchType'] = 'APT-FROM';

        $item['pickupLocation'] = $contolCentre['title'];
        $item['dropoffLocation'] = $_POST['from-name'];
        $item['pickupDate'] = $_POST['apfrom-pickup-date'];
        $item['pickupTime'] = $_POST['apfrom-pickup-time'];
        $item['dispatchedFrom'] = $dispatchedFrom;
        $item['drivingdistance'] = str_replace('km', '', $pickupKm['distance']);
        $item['drivingtime'] = $pickupKm['time'];
        $item['insurancerate'] = $rateContract['insurancerate'];
        $car['seats'] = $car['seats'] - 1;
        $item['car'] = $car;
        $item['pickuptime'] = $_POST['apfrom-pickup-time'];
        $item['id'] = uniqid();
        $item['extrakm'] = $rateContract['excessrate'];
        $item['name'] = $car['name'];
        $item['thumbnail'] = $config_imagebase . $car['thumbnail'];
        $item['baseRate'] = $rats['totalrate'];
        $item['baseCurrency'] = 'LKR';
        $item['displayRate'] = $rats['totalrate'];
        $item['displayCurrency'] = 'LKR';
        $item['breadkDown'] = $rats;
        $item['request'] = $_POST;
        $item['numberofdays'] = $dateDiff;
        $item['freemilage'] = $dateDiff * 100;

        if ($item['baseRate'] <= 0) {
            continue;
        }

        $searchResults[] = $item;
    }

    $response['results'] = $searchResults;
    $response['status'] = 'TRUE';

    return $response;
}
