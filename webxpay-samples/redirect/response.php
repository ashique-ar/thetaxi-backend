<?php

//decode & get POST parameters
$payment = base64_decode($_POST ["payment"]);
$signature = base64_decode($_POST ["signature"]);
$custom_fields = base64_decode($_POST ["custom_fields"]);
//load public key for signature matching
$publickey = "-----BEGIN PUBLIC KEY-----
MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC2+iBZQy8bG7ay9GezI6Sdcw6y
tTzmK9m8Fef8tyWVfwRvMsNX3rtKaH+S+lHHIzgaC0lTOJTrOEZ9D23iwSqG2XzH
e/QPxaALvi0gVoYtXFWi8jpZ6O1HfAUSmRSB6qRdxHc6KPBTQkG0LQZCNxqUEk/e
Y+42+v+gNB788ITpZwIDAQAB
-----END PUBLIC KEY-----";
openssl_public_decrypt($signature, $value, $publickey);

$signature_status = false ;

if($value == $payment){
	$signature_status = true ;
}

//get payment response in segments
//payment format: order_id|order_refference_number|date_time_transaction|payment_gateway_used|status_code|comment;
$responseVariables = explode('|', $payment);      

if($signature_status == true)
{
	//display values
	echo $signature_status;
	$custom_fields_varible = explode('|', $custom_fields);
	var_dump($custom_fields_varible);
	echo '<br/>';
	var_dump($responseVariables);
}else
{
	echo 'Error Validation'; 
}
	
?>  