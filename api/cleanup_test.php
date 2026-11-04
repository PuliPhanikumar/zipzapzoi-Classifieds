<?php
require_once __DIR__ . '/config.php';
$db = getDB();
// Clean test bot data
$db->exec("DELETE FROM reviews WHERE buyer_id = 9999");
$db->exec("DELETE FROM reports WHERE reporter_id = 9999");
$db->exec("DELETE FROM user_reports WHERE reported_by = 9999");
$db->exec("DELETE FROM support_tickets WHERE user_id = 9999");
$db->exec("DELETE FROM user_notifications WHERE user_id = 9999");
$db->exec("DELETE FROM wanted_ads WHERE user_id = 9999");
echo json_encode(["status" => "cleaned"]);