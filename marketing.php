<?php

$content = array();

$content[0]['time'] = date('Y-m-d H:i:s', strtotime("$last_date 6:00pm"));
$content[1]['time'] = date('Y-m-d H:i:s', strtotime("$last_date 10:00am +5 day"));
$content[2]['time'] = date('Y-m-d H:i:s', strtotime("$last_date 10:00am +10 day"));
$content[3]['time']= date('Y-m-d H:i:s', strtotime("$last_date 10:00am +15 day"));
$content[4]['time'] = date('Y-m-d H:i:s', strtotime("$last_date 10:00am +21 day"));
$content[5]['time'] = date('Y-m-d H:i:s', strtotime("$last_date 10:00am +30 day"));
$content[6]['time'] = date('Y-m-d H:i:s', strtotime("$last_date 10:00am +40 day"));
$content[7]['time']= date('Y-m-d H:i:s', strtotime("$last_date 10:00am +50 day"));

$content[0]['message'] = "omnisend, advanced";
$content[1]['message'] = "omnisend, social-care";
$content[2]['message'] = "omnisend, cannulation";
$content[3]['message'] = "omnisend, adult-care";
$content[4]['message'] = "omnisend, ecg-course";
$content[5]['message'] = "omnisend, vaccination";
$content[6]['message'] = "omnisend, care-certificate";
$content[7]['message'] = "omnisend, vitamin-b12";

$content[0]['subject'] = "Advance Your Phlebotomy Skills: Join Part 2 Training";
$content[1]['subject'] = "Join Level 3 Award in Health and Social NVQ/RQF Now!";
$content[2]['subject'] = "Join Cannulation Training: Develop Proficiency in IV Insertion";
$content[3]['subject'] = "Get Your Level 3 Diploma in Adult Care Now!";
$content[4]['subject'] = "Step Ahead in Healthcare: Join ECG Training Now";
$content[5]['subject'] = "Vaccination & Immunisation Training: Get Certified Now";
$content[6]['subject'] = "Enrol Care Certificate 15 Standards Now!";
$content[7]['subject'] = "Get Certified: Vitamin B12 Injection Training London";

foreach ($content as $item) {
    
    $wpdb->insert(
        $table_name,
        array(
            'mailto'            => $customer_email,
            'subject'           => $item['subject'],
            'content'           => $item['message'],
            'scheduled_time'    => $item['time'],
            'order_id'          => $order_id,
            'product_id'        => $product_id,
            'variation_id'      => $variation_id,
        ),
        array('%s', '%s', '%s', '%s', '%d', '%d', '%d')
        );
}



 

