<?php
/**
 * ZipZapZoi Classifieds — Buyer Demand / Wanted Ads API
 *
 * This file is the canonical endpoint the Android App calls at api/buyer_demand.php.
 * It delegates all logic to wanted.php which holds the actual implementation,
 * ensuring both the website (wanted.php) and the app (buyer_demand.php) are in sync.
 *
 * App endpoints:
 *   GET    /api/buyer_demand.php                    → list wanted ads (with filters)
 *   GET    /api/buyer_demand.php?id=X               → get single wanted ad
 *   GET    /api/buyer_demand.php?user_id=X          → get my wanted ads
 *   POST   /api/buyer_demand.php                    → create wanted ad (auth required)
 *   PUT    /api/buyer_demand.php                    → update wanted ad (auth required)
 *   DELETE /api/buyer_demand.php?id=X               → delete wanted ad (auth required)
 */
require_once __DIR__ . '/wanted.php';
