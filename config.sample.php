<?php
/* Copy this file to config.php on the server (hPanel File Manager) and fill in the keys.
   config.php is never committed to GitHub. */
return [
  // Razorpay (online payment). Leave empty to offer pay-on-installation only.
  'rzp_key'    => '',   // rzp_live_...
  'rzp_secret' => '',
  // Optional: visitor city in the Chat desk, from ScanPlay's location server (same key ScanPlay uses).
  'geo_key'    => '',
];
