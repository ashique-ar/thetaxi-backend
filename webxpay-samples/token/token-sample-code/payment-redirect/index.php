<?php

require('../functions.php');
$api = new Api('http://tokenize.stagingxpay.info/t/api/');
$jwt = $api->Auth('stagingxpay_user', 'LW8drgW5Aqia');
$details = $api->GetUserDetails($jwt);
// get other gateways
$gateways = $api->GetAvailableGateways('lkr', $jwt); // or 'usd'

//load RSA library
include_once 'Crypt/RSA.php';
//initialize RSA
$rsa = new Crypt_RSA();
// unique_order_id|total_amount
$plaintext = '525|100';
$publickey = $details->publicKey;
$secretKey = $details->secretKey;
//load public key for encrypting
$rsa->loadKey($publickey);
$encrypt = $rsa->encrypt($plaintext);
//encode for data passing
$payment = base64_encode($encrypt);
// payments
$payment = $payment;

// customer details
$customerFirstName = "Webxpay";
$customerLastName = "Client";
$customerEmail = "info@webxpay.com";
$customerAddressLineOne = "46/46";
$customerAddressLineTwo = "Green Lanka Tower";
$city = "Colombo";
$state = "Western";
$postalCode = "10300";
$country = "Sri Lanka.";
$processCurrency = "LKR"; // or USD


//checkout URL
$webxpayRedirectUrl = 'http://stagingxpay.info/index.php?route=checkout/billing';
?>

<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta http-equiv="X-UA-Compatible" content="ie=edge">
	<link rel="icon" type="image/png" href="../favicon.png" />

	<title>Other payment gateways</title>

	<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
	<link href="https://fonts.googleapis.com/css?family=Roboto:400,700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="loadingicon.css">
	<link rel="stylesheet" href="style.css">
	<script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/require.js/2.3.6/require.min.js"></script>
</head>

<body>

	<div class="container-fluid">
		<div class="container mt-3">
			<div class="merchant-info">
				<div class="row no-gutters align-items-center">
					<div class="col-auto">
						<div class="profile-image">
							<img src="<?php echo $gateways->store_logo ?>">
						</div>
					</div>
					<div class="col text-right text-white">
						<p class="title h5 text-uppercase">{{Merchant Name}}</p>
					</div>
				</div>
			</div>
			<div class="text-center">
				<div class="gateway-wrapper">
					<?php foreach ($gateways->gateway_categories as $gateway_categories) : ?>
						<p class="other-category-titles"><?php echo $gateway_categories->display_name ?></p>
						<div class="other-gateways-wrapper">
							<?php foreach ($gateways->gateways as $g) : ?>
								<?php if ($g->payment_gateway_category_id == $gateway_categories->payment_gateway_category_id) : ?>
									<div>
										<span class="gateway" data-id="<?= $g->payment_gateway_id ?>" target="_blank">
											<!-- <img class="cursor-pointer" src="<?php // echo 'http://webxpay.com/image/icnn/' . $g->logo_url  ?>"> -->
											<img class="cursor-pointer" src="<?php echo 'http://stagingxpay.info/image/icnn/' . basename($g->logo_url) ?>">
										</span>
									</div>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</div>
				<div style="display:none" class="payment-window-open">

					<div class="lds-ring">
						<div></div>
						<div></div>
						<div></div>
						<div></div>
					</div>

				</div>

			</div>
		</div>
		<div class="container">
			<footer class="row no-gutters align-items-center justify-content-center">
				<div class="col-auto">
					<p>© 2019 WEBXPAY (Pvt) Ltd</p>
				</div>
			</footer>
		</div>
	</div>

	<!-- style="display:none" -->
	<form id="submit-form" style="display:none" class="container mt-5 mb-5" action="<?php echo $webxpayRedirectUrl; ?>" method="POST">
		<input type="text" name="first_name" class="form-control" value="<?= $customerFirstName ?>">
		<input type="text" name="last_name" class="form-control" value="<?= $customerLastName ?>">
		<input type="text" name="email" class="form-control" value="<?= $customerEmail ?>">
		<input type="text" name="contact_number" class="form-control" value="<?= $customerEmail ?>">
		<input type="text" name="address_line_one" class="form-control" value="<?= $customerAddressLineOne ?>">
		<input type="text" name="address_line_two" class="form-control" value="<?= $customerAddressLineTwo ?>">
		<input type="text" name="city" class="form-control" value="<?= $city ?>">
		<input type="text" name="state" class="form-control" value="<?= $state ?>">
		<input type="text" name="postal_code" class="form-control" value="<?= $postalCode ?>">
		<input type="text" name="country" class="form-control" value="<?= $country ?>">
		<input type="text" name="process_currency" class="form-control" value="<?= $processCurrency ?>">
		<input type="text" name="cms" class="form-control" value="PHP">
		<input type="text" name="payment_gateway_id" class="form-control" value="">
		<button type="submit">submit</button>

		<!-- POST parameters -->
		<input type="hidden" name="secret_key" value="<?= $secretKey ?>">
		<input type="text" name="payment" class="form-control" value="<?php echo $payment; ?>">
	</form>
	<script>
		$(document).ready(function() {
			$('.gateway').click(function() {
				$('[name=payment_gateway_id]').val($(this).data('id'));
				$('#submit-form').submit();
			})

			let form = document.getElementById('submit-form');
			form.onsubmit = function() {
				afterPaymentWindowOpen();

				var w = window.open('about:blank', 'window', 'height=500,width=500');

				timer = setInterval(function() {
					if (w != null && w.closed) {
						console.log('payment window closed');
						clearInterval(timer)
						afterPaymentWindowClosed();
					}
				}, 200);

				this.target = 'window';
			}

		});

		function afterPaymentWindowOpen() {
			$('.gateway-wrapper').hide('false');
			$('.payment-window-open').show('false');
		}

		function afterPaymentWindowClosed() {
			$('.gateway-wrapper').show('false');
			$('.payment-window-open').hide('false');
		}
	</script>

</body>

</html>