/**
 * ZipZapZoi Classifieds — Central Master Schema
 * 
 * This file serves as the Single Source of Truth for all Categories, 
 * Subcategories, and metadata across the entire platform.
 * 
 * Both the Admin Console and the Public Marketplace MUST pull from this schema.
 */

const ZZZ_SCHEMA = {
  categories: [ 
    { id: 'electronics', label: 'Electronics', icon: 'devices', color: 'text-cyan-500' }, 
    { id: 'vehicles', label: 'Vehicles', icon: 'directions_car', color: 'text-blue-600' }, 
    { id: 'home', label: 'Home & Garden', icon: 'yard', color: 'text-green-600' }, 
    { id: 'clothing', label: 'Clothing', icon: 'checkroom', color: 'text-pink-500' },
    { id: 'real_estate', label: 'Real Estate', icon: 'real_estate_agent', color: 'text-purple-600' },
    { id: 'jobs', label: 'Jobs', icon: 'work', color: 'text-red-500', priceLabel: 'Salary (Approx)' },
    { id: 'services', label: 'Services', icon: 'handyman', color: 'text-orange-500', photoLimit: 4 },
    { id: 'sports', label: 'Sports', icon: 'sports_soccer', color: 'text-teal-500', photoLimit: 4 },
    { id: 'pets', label: 'Pets', icon: 'pets', color: 'text-yellow-600', photoLimit: 6 },
    { id: 'tourism', label: 'Tourism', icon: 'flight', color: 'text-sky-500', photoLimit: 6 },
    { id: 'doctors', label: 'Doctors', icon: 'stethoscope', color: 'text-rose-600', priceLabel: 'Consultancy Fee', photoLimit: 4 },
    { id: 'charity', label: 'Charity', icon: 'volunteer_activism', color: 'text-green-500', hidePrice: true, photoLimit: 2, note: 'Charity listings are free.' },
    { id: 'food', label: 'Restaurant & Food', icon: 'restaurant', color: 'text-orange-600', photoLimit: 6 },
    { id: 'events', label: 'Events', icon: 'celebration', color: 'text-purple-500', photoLimit: 6 }
  ],
  subcategories: [
    // ELECTRONICS
    { id: 'mobiles', categoryId: 'electronics', label: 'Mobiles & Accessories' },
    { id: 'laptops', categoryId: 'electronics', label: 'Computers & Laptops' },
    { id: 'appliances', categoryId: 'electronics', label: 'Home Appliances' },
    { id: 'cameras', categoryId: 'electronics', label: 'Cameras & Photography' },
    { id: 'tv_audio', categoryId: 'electronics', label: 'TVs - Audio & Video' },
    { id: 'gaming', categoryId: 'electronics', label: 'Gaming & Consoles' },
    { id: 'wearables', categoryId: 'electronics', label: 'Wearables & Smart Devices' },
    { id: 'kitchen_elec', categoryId: 'electronics', label: 'Kitchen Electronics' },
    { id: 'office_elec', categoryId: 'electronics', label: 'Office Electronics' },
    { id: 'other_elec', categoryId: 'electronics', label: 'Other Electronics' },
    // VEHICLES
    { id: 'cars', categoryId: 'vehicles', label: 'Cars' },
    { id: 'bikes', categoryId: 'vehicles', label: 'Bikes & Scooters' },
    { id: 'commercial', categoryId: 'vehicles', label: 'Commercial Vehicles' },
    { id: 'autos', categoryId: 'vehicles', label: 'Auto Rickshaws & Three-Wheelers' },
    { id: 'ev', categoryId: 'vehicles', label: 'Electric Vehicles' },
    { id: 'bicycles', categoryId: 'vehicles', label: 'Bicycles' },
    { id: 'parts', categoryId: 'vehicles', label: 'Spare Parts' },
    { id: 'boats', categoryId: 'vehicles', label: 'Boats & Other Vehicles' },
    // HOME
    { id: 'furniture', categoryId: 'home', label: 'Furniture' },
    { id: 'decor', categoryId: 'home', label: 'Home Decor' },
    { id: 'kitchen', categoryId: 'home', label: 'Kitchen & Dining' },
    { id: 'garden', categoryId: 'home', label: 'Plants & Garden' },
    { id: 'tools', categoryId: 'home', label: 'Tools & Hardware' },
    { id: 'cleaning', categoryId: 'home', label: 'Cleaning & Household' },
    { id: 'bedding', categoryId: 'home', label: 'Bedding & Bath' },
    { id: 'home_other', categoryId: 'home', label: 'Others' },
    // CLOTHING
    { id: 'men', categoryId: 'clothing', label: 'Men Clothing' },
    { id: 'women', categoryId: 'clothing', label: 'Women Clothing' },
    { id: 'kids', categoryId: 'clothing', label: 'Kids Clothing' },
    { id: 'sports_wear', categoryId: 'clothing', label: 'Sports Wear' },
    { id: 'footwear', categoryId: 'clothing', label: 'Foot Wear' },
    { id: 'ethnic', categoryId: 'clothing', label: 'Ethnic & Traditional' },
    { id: 'cloth_acc', categoryId: 'clothing', label: 'Accessories & Others' },
    // REAL ESTATE
    { id: 'residential', categoryId: 'real_estate', label: 'Residential Properties' },
    { id: 'commercial_re', categoryId: 'real_estate', label: 'Commercial Properties' },
    { id: 'rentals', categoryId: 'real_estate', label: 'Rental Listings' },
    { id: 'plots', categoryId: 'real_estate', label: 'Land & Plots' },
    { id: 'special_re', categoryId: 'real_estate', label: 'Special Categories' },
    { id: 're_services', categoryId: 'real_estate', label: 'Real Estate Services' },
    { id: 'pg', categoryId: 'real_estate', label: 'PG / Hostel' },
    { id: 'timeshares', categoryId: 'real_estate', label: 'Timeshares' },
    // JOBS
    { id: 'it_jobs', categoryId: 'jobs', label: 'IT & Software' },
    { id: 'sales_jobs', categoryId: 'jobs', label: 'Sales & Marketing' },
    { id: 'finance', categoryId: 'jobs', label: 'Finance & Accounting' },
    { id: 'hr_jobs', categoryId: 'jobs', label: 'HR & Admin' },
    { id: 'bpo', categoryId: 'jobs', label: 'Customer Service / BPO' },
    { id: 'education_jobs', categoryId: 'jobs', label: 'Education & Training' },
    { id: 'health_jobs', categoryId: 'jobs', label: 'Healthcare' },
    { id: 'eng_jobs', categoryId: 'jobs', label: 'Engineering' },
    { id: 'construction', categoryId: 'jobs', label: 'Construction & Trades' },
    { id: 'hospitality', categoryId: 'jobs', label: 'Hospitality & Travel' },
    { id: 'retail', categoryId: 'jobs', label: 'Retail' },
    { id: 'logistics', categoryId: 'jobs', label: 'Transport & Logistics' },
    { id: 'media', categoryId: 'jobs', label: 'Media & Creative' },
    { id: 'gov', categoryId: 'jobs', label: 'Government & Public Sector' },
    { id: 'freelance', categoryId: 'jobs', label: 'Part-time / Freelance' },
    // SERVICES
    { id: 'home_svc', categoryId: 'services', label: 'Home Services' },
    { id: 'auto_svc', categoryId: 'services', label: 'Automotive Services' },
    { id: 'health_svc', categoryId: 'services', label: 'Health & Wellness' },
    { id: 'beauty', categoryId: 'services', label: 'Beauty & Salon' },
    { id: 'tutors', categoryId: 'services', label: 'Education/Tutors' },
    { id: 'astrology', categoryId: 'services', label: 'Astrology' },
    { id: 'other_svc', categoryId: 'services', label: 'Others' },
    // SPORTS
    { id: 'sports_eq', categoryId: 'sports', label: 'Sports Equipment' },
    { id: 'fitness', categoryId: 'sports', label: 'Fitness & Training' },
    { id: 'outdoor', categoryId: 'sports', label: 'Outdoor & Adventure' },
    { id: 'team_sports', categoryId: 'sports', label: 'Team Sports' },
    { id: 'indoor', categoryId: 'sports', label: 'Indoor Games' },
    { id: 'athletic', categoryId: 'sports', label: 'Athletic Apparel' },
    { id: 'tickets', categoryId: 'sports', label: 'Tickets & Memberships' },
    { id: 'coaching', categoryId: 'sports', label: 'Coaching & Training' },
    { id: 'water', categoryId: 'sports', label: 'Water Sports' },
    { id: 'winter', categoryId: 'sports', label: 'Winter Sports' },
    // PETS
    { id: 'pet_adopt', categoryId: 'pets', label: 'Pets / Adoption' },
    { id: 'pet_acc', categoryId: 'pets', label: 'Pet Accessories' },
    { id: 'pet_food', categoryId: 'pets', label: 'Pet Food & Nutrition' },
    { id: 'pet_svc', categoryId: 'pets', label: 'Pet Services' },
    { id: 'pet_health', categoryId: 'pets', label: 'Pet Health & Care' },
    // TOURISM
    { id: 'travel_pkg', categoryId: 'tourism', label: 'Travel Packages' },
    { id: 'transport', categoryId: 'tourism', label: 'Transport Services' },
    { id: 'accommodation', categoryId: 'tourism', label: 'Accommodation' },
    { id: 'adventure', categoryId: 'tourism', label: 'Adventure & Activities' },
    { id: 'local_exp', categoryId: 'tourism', label: 'Local Experiences' },
    { id: 'holiday', categoryId: 'tourism', label: 'Holiday Essentials' },
    { id: 'events_attr', categoryId: 'tourism', label: 'Events & Attractions' },
    { id: 'visa', categoryId: 'tourism', label: 'Visa Services' },
    // DOCTORS
    { id: 'general', categoryId: 'doctors', label: 'General & Family Medicine' },
    { id: 'specialists', categoryId: 'doctors', label: 'Specialists' },
    { id: 'womens_health', categoryId: 'doctors', label: 'Women\'s Health' },
    { id: 'child_health', categoryId: 'doctors', label: 'Children\'s Health' },
    { id: 'dentist', categoryId: 'doctors', label: 'Dental Care' },
    { id: 'mental_health', categoryId: 'doctors', label: 'Mental Health' },
    { id: 'surgical', categoryId: 'doctors', label: 'Surgical Services' },
    { id: 'alternative', categoryId: 'doctors', label: 'Alternative Care' },
    { id: 'diagnostics', categoryId: 'doctors', label: 'Diagnostics & Support' },
    // CHARITY
    { id: 'edu_aid', categoryId: 'charity', label: 'Education Aid' },
    { id: 'medical_aid', categoryId: 'charity', label: 'Medical & Healthcare' },
    { id: 'food_aid', categoryId: 'charity', label: 'Food & Essentials' },
    { id: 'disaster', categoryId: 'charity', label: 'Disaster Relief' },
    { id: 'animal_aid', categoryId: 'charity', label: 'Animal Welfare' },
    { id: 'community', categoryId: 'charity', label: 'Community Development' },
    { id: 'women_child', categoryId: 'charity', label: 'Women & Child Welfare' }
  ],
  fields: [
    // ELECTRONICS
    { id: 'brand_mob', subcategoryId: 'mobiles', label: 'Brand Name', type: 'text', required: true },
    { id: 'cond_mob', subcategoryId: 'mobiles', label: 'Condition', type: 'select', options: 'New, Used - Good, Used - Fair, Refurbished', required: true },
    { id: 'brand_lap', subcategoryId: 'laptops', label: 'Brand Name', type: 'text', required: true },
    { id: 'proc', subcategoryId: 'laptops', label: 'Processor', type: 'text' },
    { id: 'ram', subcategoryId: 'laptops', label: 'RAM', type: 'select', options: '4GB, 8GB, 16GB, 32GB' },
    
    // VEHICLES
    { id: 'v_brand', subcategoryId: 'cars', label: 'Brand/Make', type: 'text', required: true },
    { id: 'v_year', subcategoryId: 'cars', label: 'Year of Manufacture', type: 'number', required: true },
    { id: 'v_fuel', subcategoryId: 'cars', label: 'Fuel Type', type: 'select', options: 'Petrol, Diesel, CNG, Electric, Hybrid' },
    { id: 'v_trans', subcategoryId: 'cars', label: 'Transmission', type: 'select', options: 'Manual, Automatic' },
    { id: 'v_km', subcategoryId: 'cars', label: 'KM Driven', type: 'number' },
    { id: 'v_owners', subcategoryId: 'cars', label: 'Type of Owner', type: 'select', options: '1st, 2nd, 3rd, 4th, 5th+' },

    // REAL ESTATE
    { id: 're_type', subcategoryId: 'residential', label: 'Listing Type', type: 'select', options: 'For Sale, For Rent' },
    { id: 're_listedby', subcategoryId: 'residential', label: 'Listed By', type: 'select', options: 'Builder, Dealer, Owner' },
    { id: 're_area', subcategoryId: 'residential', label: 'Area Size (sq ft)', type: 'number', required: true },
    { id: 're_len', subcategoryId: 'residential', label: 'Length', type: 'number' },
    { id: 're_br', subcategoryId: 'residential', label: 'Breadth', type: 'number' },
    { id: 're_face', subcategoryId: 'residential', label: 'Facing', type: 'select', options: 'East, West, North, South, North-East, North-West, South-East, South-West' },
    { id: 're_proj', subcategoryId: 'residential', label: 'Project Name', type: 'text' },

    // JOBS
    { id: 'j_role', subcategoryId: 'it_jobs', label: 'Position Type', type: 'select', options: 'Full-time, Part-time, Contract, Internship, Freelance' },
    { id: 'j_sal_min', subcategoryId: 'it_jobs', label: 'Salary From', type: 'number' },
    { id: 'j_sal_max', subcategoryId: 'it_jobs', label: 'Salary To', type: 'number' },
    
    // DOCTORS
    { id: 'd_qual', subcategoryId: 'general', label: 'Qualification', type: 'text', placeholder: 'e.g. MBBS, MD' },
    { id: 'd_exp', subcategoryId: 'general', label: 'Experience (Years)', type: 'number' },
    { id: 'd_avail', subcategoryId: 'general', label: 'Availability', type: 'text', placeholder: 'Mon-Sat 10am-6pm' },
    
    // CHARITY
    { id: 'c_date', subcategoryId: 'edu_aid', label: 'Day / Date', type: 'text', placeholder: 'e.g. 2024-12-01' },

    // FOOD
    { id: 'f_fssai', subcategoryId: 'homemade', label: 'FSSAI License (Optional)', type: 'text' },
    { id: 'f_type', subcategoryId: 'homemade', label: 'Type', type: 'select', options: 'Veg, Non-Veg, Both' },

    // EVENTS
    { id: 'e_cap', subcategoryId: 'weddings', label: 'Capacity (Pax)', type: 'number' },
    { id: 'e_book', subcategoryId: 'weddings', label: 'Booking Type', type: 'select', options: 'Advance Required, Full Payment' }
  ]
};

// Expose globally
window.ZZZ_SCHEMA = ZZZ_SCHEMA;
