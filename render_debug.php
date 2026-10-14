<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

request()->headers->set('host', 'casons.lk');
app()->instance('request', request());

$errors = new \Illuminate\Support\ViewErrorBag;
view()->share('errors', $errors);
$html = view('components.booking-form', ['errors' => $errors])->render();

if (preg_match('/<form[^>]*id="self_drive-form"[^>]*>(.*?)<\/form>/is', $html, $matches)) {
    echo "--- SELF DRIVE FORM CONTENT ---\n";
    echo substr($matches[1], 0, 1500) . "\n...\n";
    // Count form-group / inputs
    preg_match_all('/<label[^>]*>(.*?)<\/label>/is', $matches[1], $labels);
    echo "Labels found: " . implode(' | ', array_map('trim', array_map('strip_tags', $labels[1]))) . PHP_EOL;
    preg_match_all('/<button[^>]*>(.*?)<\/button>/is', $matches[1], $buttons);
    echo "Buttons found: " . implode(' | ', array_map('trim', array_map('strip_tags', $buttons[1]))) . PHP_EOL;
}
