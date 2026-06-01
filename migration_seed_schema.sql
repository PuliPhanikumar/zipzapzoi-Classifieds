-- ================================================================
-- ZipZapZoi — Seed Default Categories into system_settings
-- Run ONCE in: Hostinger phpMyAdmin → u572945141_Classifieds_db → SQL
-- This seeds all 14 default categories so ALL users see them immediately.
-- ================================================================

INSERT INTO `system_settings` (`setting_key`, `setting_value`)
VALUES (
  'classifieds_schema',
  '{
    "categories": [
      {"id":"electronics","label":"Electronics","icon":"devices","color":"text-cyan-500"},
      {"id":"vehicles","label":"Vehicles","icon":"directions_car","color":"text-blue-600"},
      {"id":"home","label":"Home & Garden","icon":"yard","color":"text-green-600"},
      {"id":"clothing","label":"Clothing","icon":"checkroom","color":"text-pink-500"},
      {"id":"real_estate","label":"Real Estate","icon":"real_estate_agent","color":"text-purple-600"},
      {"id":"jobs","label":"Jobs","icon":"work","color":"text-red-500","priceLabel":"Salary (Approx)"},
      {"id":"services","label":"Services","icon":"handyman","color":"text-orange-500","photoLimit":4},
      {"id":"sports","label":"Sports","icon":"sports_soccer","color":"text-teal-500","photoLimit":4},
      {"id":"pets","label":"Pets","icon":"pets","color":"text-yellow-600","photoLimit":6},
      {"id":"tourism","label":"Tourism","icon":"flight","color":"text-sky-500","photoLimit":6},
      {"id":"doctors","label":"Doctors","icon":"stethoscope","color":"text-rose-600","priceLabel":"Consultancy Fee","photoLimit":4},
      {"id":"charity","label":"Charity","icon":"volunteer_activism","color":"text-green-500","hidePrice":true,"photoLimit":2,"note":"Charity listings are free."},
      {"id":"food","label":"Restaurant & Food","icon":"restaurant","color":"text-orange-600","photoLimit":6},
      {"id":"events","label":"Events","icon":"celebration","color":"text-purple-500","photoLimit":6}
    ],
    "subcategories": [
      {"id":"mobiles","categoryId":"electronics","label":"Mobiles & Accessories"},
      {"id":"laptops","categoryId":"electronics","label":"Computers & Laptops"},
      {"id":"cameras","categoryId":"electronics","label":"Cameras & Optics"},
      {"id":"tvs","categoryId":"electronics","label":"TVs & Home Theater"},
      {"id":"audio","categoryId":"electronics","label":"Audio & Headphones"},
      {"id":"gaming","categoryId":"electronics","label":"Gaming"},
      {"id":"appliances","categoryId":"electronics","label":"Home Appliances"},
      {"id":"cars","categoryId":"vehicles","label":"Cars"},
      {"id":"motorcycles","categoryId":"vehicles","label":"Motorcycles & Scooters"},
      {"id":"trucks","categoryId":"vehicles","label":"Trucks & Commercial"},
      {"id":"cycles","categoryId":"vehicles","label":"Cycles"},
      {"id":"ev","categoryId":"vehicles","label":"Electric Vehicles"},
      {"id":"spares","categoryId":"vehicles","label":"Spare Parts & Accessories"},
      {"id":"furniture","categoryId":"home","label":"Furniture"},
      {"id":"kitchenware","categoryId":"home","label":"Kitchenware"},
      {"id":"decor","categoryId":"home","label":"Home Decor"},
      {"id":"gardening","categoryId":"home","label":"Gardening & Plants"},
      {"id":"tools","categoryId":"home","label":"Tools & Equipment"},
      {"id":"mens","categoryId":"clothing","label":"Men''s Clothing"},
      {"id":"womens","categoryId":"clothing","label":"Women''s Clothing"},
      {"id":"kids_clothes","categoryId":"clothing","label":"Kids & Baby Clothing"},
      {"id":"footwear","categoryId":"clothing","label":"Footwear"},
      {"id":"bags","categoryId":"clothing","label":"Bags & Accessories"},
      {"id":"flats","categoryId":"real_estate","label":"Flats & Apartments"},
      {"id":"houses","categoryId":"real_estate","label":"Independent Houses"},
      {"id":"plots","categoryId":"real_estate","label":"Plots & Land"},
      {"id":"commercial","categoryId":"real_estate","label":"Commercial Property"},
      {"id":"pg","categoryId":"real_estate","label":"PG / Roommates"},
      {"id":"fulltime","categoryId":"jobs","label":"Full-Time Jobs"},
      {"id":"parttime","categoryId":"jobs","label":"Part-Time Jobs"},
      {"id":"freelance","categoryId":"jobs","label":"Freelance / Remote"},
      {"id":"internship","categoryId":"jobs","label":"Internships"},
      {"id":"home_services","categoryId":"services","label":"Home & Repair"},
      {"id":"tutoring","categoryId":"services","label":"Tutoring & Classes"},
      {"id":"beauty","categoryId":"services","label":"Beauty & Wellness"},
      {"id":"transport","categoryId":"services","label":"Transport & Moving"},
      {"id":"fitness","categoryId":"sports","label":"Fitness Equipment"},
      {"id":"outdoor","categoryId":"sports","label":"Outdoor & Adventure"},
      {"id":"team_sports","categoryId":"sports","label":"Team Sports"},
      {"id":"dogs","categoryId":"pets","label":"Dogs"},
      {"id":"cats","categoryId":"pets","label":"Cats"},
      {"id":"birds","categoryId":"pets","label":"Birds"},
      {"id":"pet_supplies","categoryId":"pets","label":"Pet Supplies & Accessories"},
      {"id":"hotels","categoryId":"tourism","label":"Hotels & Stays"},
      {"id":"tours","categoryId":"tourism","label":"Tour Packages"},
      {"id":"vehicles_rent","categoryId":"tourism","label":"Vehicle Rentals"},
      {"id":"general_practitioners","categoryId":"doctors","label":"General Practitioners"},
      {"id":"specialists","categoryId":"doctors","label":"Specialists"},
      {"id":"dentists","categoryId":"doctors","label":"Dentists"},
      {"id":"charity_food","categoryId":"charity","label":"Food & Groceries"},
      {"id":"charity_clothes","categoryId":"charity","label":"Clothes & Essentials"},
      {"id":"charity_other","categoryId":"charity","label":"Other Donations"},
      {"id":"restaurants","categoryId":"food","label":"Restaurants"},
      {"id":"cloud_kitchens","categoryId":"food","label":"Cloud Kitchens & Delivery"},
      {"id":"catering","categoryId":"food","label":"Catering Services"},
      {"id":"local_events","categoryId":"events","label":"Local Events"},
      {"id":"online_events","categoryId":"events","label":"Online Events & Webinars"},
      {"id":"cultural","categoryId":"events","label":"Cultural & Festivals"}
    ],
    "fields": []
  }'
)
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- Verify it was saved:
SELECT setting_key, LEFT(setting_value, 80) AS preview FROM system_settings WHERE setting_key = 'classifieds_schema';
