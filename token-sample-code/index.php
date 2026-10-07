
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

 $url = $actual_link = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

 $customer_email = 'jamesx@wxp.com' ;
 $customer_id = '008' ;
 $amount = 18;
 // $url =   strtok("//{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}", '?');

 // echo $url ;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <link rel="icon" type="image/png" href="favicon.png" />
    <title>WEBXPAY Tokenize Client</title>

    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>

    <!-- PREVENT CLICK-JACKING -->
    <style id="antiClickjack">
        body {
            display: none !important;
        }
    </style>
    <script type="text/javascript">
        if (self === top) {
            var antiClickjack = document.getElementById("antiClickjack");
            antiClickjack.parentNode.removeChild(antiClickjack);
        } else {
            top.location = self.location;
        }
    </script>

    <?php
    $currency = "LKR";
    $mid = "TESTWEBXPATOKLKR";
    ?>

    <!-- Cargills Bank TEST-->
    <!-- <script src="https://test-gateway.mastercard.com/form/version/55/merchant/<?php echo $mid ?>/session.js"></script> -->

    <!-- Cargills Bank LIVE -->
    <!-- <script src="https://ap-gateway.mastercard.com/form/version/55/merchant/<?php echo $mid ?>/session.js"></script> -->

    <!-- Commercial Bank TEST/LIVE -->
    <script src="https://cbcmpgs.gateway.mastercard.com/form/version/63/merchant/<?php echo $mid ?>/session.js"></script>

    <script src="webxpay.hostedsession.js"></script>
</head>

<?php
require('functions.php');

// $api = new Api('https://tokenize.webxpay.com/v1/api/'); // LIVE Commercial Bank
// $api = new Api('https://tokenize.stagingxpay.info/t/api/'); // TEST Commercial
//$api = new Api('https://tokenize.stagingxpay.info/cargills/api/'); // TEST Cargills Bank
// $api = new Api('https://tokenize.webxpay.com/cargills/api/'); // LIVE Cargills Bank

$api = new Api('https://tokenize.stagingxpay.info/t/api/'); // TEST Cargills Bank

// $jwt = $api->Auth('{provided username}', '{provided password}');
$jwt = $api->Auth('shuaib', 'M9SZKS)7A2J#');

// get customer cards
$cards = $api->GetCustomerCards(
    array("customer" => array("id" => $customer_id, "email" => $customer_email)),
    $jwt
);
?>

<body>

    <?php
    // display 3ds result
    if (isset($_GET['result3ds'])) {
        $result = base64_decode($_GET['result3ds']);
        showResult(json_decode($result));
    }

    // save card
    

    if (isset($_GET['session'])) {

        $session = $_GET['session'];

        $result = $api->SaveCustomerCard(array(
            "session" => "$session",
            "currency" => "$currency",
            "bankMID" => "$mid",
            "secure3dResponseURL" => "$url",
            "customer" => array(
                "id" => $customer_id,
                "email" => $customer_email,
                "firstName" => "jamesx",
                "lastName" => "gordan",
                "contactNumber" => "0111111111",
                "addressLineOne" => "sample line1",
                "city" => "colombo",
                "postalCode" => "78151",
                "country" => "srilanka"
            ),
        ), $jwt);

        if ($result->error) {
            if ($result->type == "3ds") {
            ?>
                <form id="3dsRedirect" method="POST" action="3dsRedirect.php">
                    <input type="hidden" name="3dshtml" value="<?php echo htmlentities($result->html3ds) ?>" />
                    <div class="text-center">

                        <button class="btn btn-danger mt-2" type="submit">Redirect for 3DS</button>
                    </div>
                </form>
                <script>
                    $(document).ready(function() {
                        // document.getElementById('3dsRedirect').submit();
                    });
                </script>
    <?php
            }
        }

        showResult($result);
    }

    // delete card
    if (isset($_REQUEST['deleteCard'])) {
        $cardId = $_REQUEST['cardId'];
        //$cardLast = $_REQUEST['cardLast'];
        //$cardFirst = $_REQUEST['cardFirst'];
        $result = $api->DeleteCard(array('cardId' => '4111111111' ,'customerEmail' => $customer_email, "customerId" => $customer_id), $jwt);

        showResult($result);
        var_dump($cardId);
        die();
    }

    // pay from card
    if (isset($_REQUEST['payFromCard'])) {
        $cardLast = $_REQUEST['cardLast'];
        $cardFirst = $_REQUEST['cardFirst'];

        $orderNumber = rand(0, 10000);
        $result = $api->PayFromCard(
            array(
                "amount" =>  "$amount",
                "cardLast" =>  "$cardLast",
                "cardFirst" =>  "$cardFirst",
                "orderNumber" =>  "$orderNumber",
                "currency" =>  "$currency",
                "customer" => array(
                    "id" => $customer_id,
                    "email" => $customer_email,
                )
            ),
            $jwt
        );
        showResult($result);

        die();
    }


    // $url =   strtok("//{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}", '?');

    // pay from card
    if (isset($_REQUEST['payFromCard3Ds'])) {
        //$cardId = $_REQUEST['cardId'];
        $cardLast = $_REQUEST['cardLast'];
        $cardFirst = $_REQUEST['cardFirst'];
        $orderNumber = rand(0, 10000);
        $result = $api->PayFromCard3ds(
            array(
                "cardId" => $cardFirst.$cardLast,
                "bankMID" => "$mid",
                "secure3dResponseURL" => "$url",
                "amount" =>  "$amount",
                //"cardLast" =>  "$cardLast",
                //"cardFirst" =>  "$cardFirst",
                "orderNumber" =>  "$orderNumber",
                "currency" =>  "$currency",
                "customer" => array(
                    "id" => $customer_id,
                    "email" => $customer_email,
                )
            ),
            $jwt
        );

        if (isset($result->error)) {
            if ($result->type == "3ds") {
    ?>
                <form id="3dsRedirect" method="POST" action="http://localhost:8888/php%20V-2.0%20-encr/3dResponse.php">
                    <input type="hidden" name="3dshtml" value="<?php echo htmlentities($result->html3ds) ?>" />
                    <div class="text-center">

                        <button class="btn btn-danger mt-2" type="submit">Redirect for 3DS</button>
                    </div>
                </form>
                <script>
                    $(document).ready(function() {
                        // document.getElementById('3dsRedirect').submit();
                    });
                </script>
            <?php
            }
        }

        showResult($result);

        die();
    }


    $customData = array(
        "product_id" => "45",
        "other_data" => "",
        "uniq_order_id" => "4564",
        "order_reference_number" => "TSXFFF",
    );
    $jsn_customData = base64_encode(json_encode($customData));

    // pay from session
    if (isset($_REQUEST['sessionpay3ds'])) {
        $session = $_REQUEST['sessionpay3ds'];
        $orderNumber = bin2hex(openssl_random_pseudo_bytes(16));
        $result = $api->PayFromSession3ds(
            array(
                "amount" =>  "$amount",
                "session" =>  "$session",
                "orderNumber" =>  "$orderNumber",
                "currency" =>  "$currency",
                "bankMID" => "$mid",
                //"customData" => "$jsn_customData",
                "secure3dResponseURL" => "$url",

                "customer" => array(
                    "id" => $customer_id,
                    "email" => $customer_email,
                    "firstName" => "jamesx",
                    "lastName" => "gordan",
                    "contactNumber" => "0111111111",
                    "addressLineOne" => "sample line1",
                    "city" => "colombo",
                    "postalCode" => "78151",
                    "country" => "srilanka"
                )
            ),
            $jwt
        );

        if ($result->error) {
            if ($result->type == "3ds") {
            ?>
                <form id="3dsRedirect" method="POST" action="3dsRedirect.php">
                    <input type="hidden" name="3dshtml" value="<?php echo htmlentities($result->html3ds) ?>" />
                    <div class="text-center">

                        <button class="btn btn-danger mt-2" type="submit">Redirect for 3DS</button>
                    </div>
                </form>
                <script>
                    $(document).ready(function() {
                        // document.getElementById('3dsRedirect').submit();
                    });
                </script>
    <?php
            }
        }

        showResult($result);
    }

    function showResult($data)
    {
        echo '<div class="container">';
        $base_url = "http://" . $_SERVER['SERVER_NAME'] . dirname($_SERVER["REQUEST_URI"] . '?') . '/';

        highlight_string("<?php\n\$data =\n" . var_export($data, true) . ";\n?>");
        echo '<br>';
        echo "<a href=$base_url class='btn btn-primary'>Home</a>";
        echo '</div>';
    }

    ?>

    <h2 class="text-center">WEBXPAY CLIENT - PHP</h2>

    <div class="container">
        <p class="title h6 text-uppercase">pay from session or tokenize</p>
        <div class="new-card-wrapper">
            <div class="form-group">
                <label>Card Number:</label>
                <input type="text" id="card-number" class="form-control" title="card number" readonly />
                <span class="text-danger card-number-error err"></span>
            </div>
            <div class="form-group">
                <label for="">Expiry Month: <small>(MM)</small></label>
                <input type="text" id="expiry-month" class="form-control" title="expiry month" readonly />
                <span class="text-danger exp-month-error err"></span>
            </div>
            <div class="form-group">
                <label for="">Expiry Year: <small>(YYYY)</small></label>
                <input type="text" id="expiry-year" class="form-control" title="expiry year" readonly />
                <span class="text-danger exp-year-error err"></span>
            </div>
            <div class="form-group">
                <label for="">Security Code:</label>
                <input type="text" id="security-code" class="form-control" title="security code" readonly />
                <span class="text-danger cvv-error err"></span>
            </div>
            <div class="form-group">
                <label for="">Cardholder Name:</label>
                <input type="text" id="cardholder-name" class="form-control" title="card-holder name" readonly />
                <span class="text-danger card-holder-error err"></span>
            </div>

            <div class="general-error text-danger">

            </div>
            <div class="mt-5 text-right">
                <div class="p-2 rounded no-gutters bg-light row align-items-end justify-content-end">
                    <div class="col-auto">
                        <a href="#" class="btn btn-secondary" id="save-card-button">Save</a>
                    </div>
                    <div class="col-auto">
                        <div class="">
                            <div class="col-12">
                                <p class="text-info p-2 mb-0">Amount: <?php echo number_format($amount,2) ?></p>
                            </div>
                            <div class="col-12">
                                <a href="#" class="btn btn-primary" id="pay-from-card3ds-button">Pay from session</a>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>

        </div>
    </div>

    <div class="container">
        <p class="title h6 text-uppercase">pay from previously saved token</p>
        <p class="badge badge-info p-2">Amount: <?php echo number_format($amount,2) ?></p>
        <?php foreach ($cards as $c) : ?>
            <div class="card-number">
                <?php echo $c->cardFirst ?>
                <?php echo '*********' ?>
                <?php echo $c->cardLast ?>

                <a href="?deleteCard=true&cardLast=<?php echo $c->cardLast ?>&cardFirst=<?php echo $c->cardFirst ?>" class="btn btn-danger">Delete</a>
                <a href="?payFromCard3Ds=true&cardLast=<?php echo $c->cardLast ?>&cardFirst=<?php echo $c->cardFirst ?>" class="btn btn-primary">Pay from this card with 3DS</a>
                <a href="?payFromCard=true&cardLast=<?php echo $c->cardLast ?>&cardFirst=<?php echo $c->cardFirst ?>" class="btn btn-primary">Pay from this card</a>

            </div>
        <?php endforeach; ?>
    </div>

    <div class="container">
        <a href="payment-redirect" class="btn btn-success">Other payment methods</a>
    </div>

    <script>
        let url = "/";
        let type = '';
        // init webxpay
        WebxpayTokenizeInit({
            card: {
                number: "#card-number",
                securityCode: "#security-code",
                expiryMonth: "#expiry-month",
                expiryYear: "#expiry-year",
                nameOnCard: "#cardholder-name",
            },
            ready: afterInit,
        });

        function afterInit(GenerateSession) {
            // save card
            $('#save-card-button').click(function() {
                $('#save-card-button').attr('disabled', true);

                GenerateSession(
                    function(session) {
                        console.log(session);
                        $(this).removeAttr("disabled");
                        window.location.href = "?session=" + session;
                    },
                    function(error) {
                        handleErrors(error);
                    }
                );
            });

            

            $('#pay-from-card3ds-button').click(function() {
                $('#pay-from-card3ds-button').attr('disabled', true);

                GenerateSession(
                    function(session) {
                        $(this).removeAttr("disabled");
                        window.location.href = "?sessionpay3ds=" + session;
                    },
                    function(error) {
                        handleErrors(error);
                    }
                );
            });
        }

        function handleErrors(error) {
            $('button').removeAttr('disabled');

            $('.err').html('');
            $('.general-error').html('');

            switch (error.type) {
                case 'fields_in_error': {
                    if (error.details.cardNumber) {
                        if (error.details.cardNumber == 'missing') {
                            $('.card-number-error').html('Enter valid card number');
                        }
                        if (error.details.cardNumber == 'invalid') {
                            $('.card-number-error').html('Invalid card number');
                        }
                    }
                    if (error.details.expiryMonth) {
                        if (error.details.expiryMonth == 'missing') {
                            $('.exp-month-error').html('Enter expiration month');
                        }
                        if (error.details.expiryMonth == 'invalid') {
                            $('.exp-month-error').html('Invalid expiration month');
                        }
                    }
                    if (error.details.expiryYear) {
                        if (error.details.expiryYear == 'missing') {
                            $('.exp-year-error').html('Enter expiration year');
                        }
                        if (error.details.expiryYear == 'invalid') {
                            $('.exp-month-error').html('Invalid expiration year');
                        }
                    }
                    if (error.details.securityCode) {
                        if (error.details.securityCode == 'missing') {
                            $('.cvv-error').html('Enter CVV');
                        }
                        if (error.details.securityCode == 'invalid') {
                            $('.cvv-error').html('Invalid CVV');
                        }
                    }
                    console.error('missing card details', error.details);
                    break;
                }
                case 'request_timeout': {
                    $('.general-error').html('<span class="text-decoration-uppercase">' + error.details + '</span>')
                    console.error('request time out', error.details);
                    break;
                }
                case 'system_error': {
                    if (error.details == 'cvv missing') {
                        $('.general-error').html('Enter CVV details');
                    } else {
                        $('.general-error').html(error.details);
                    }
                    console.error('system error', error.details);
                    break;
                }
            }
        }
    </script>

</body>

</html>
