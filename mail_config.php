<?php
// ==========================================================
// EMAIL SETTINGS - edit this file ONCE with your school's email account.
// The system uses it to email login details to teachers.
//
// GMAIL SETUP (easiest):
//  1. Open your Google Account > Security > turn ON "2-Step Verification".
//  2. Search "App passwords" in the Google Account page, create one named "School".
//  3. Copy the 16-letter code it shows and paste it as 'password' below (no spaces).
//  Do NOT use your normal Gmail password - it will be refused.
// ==========================================================
return [
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'secure'     => 'tls',                       // 'tls' for port 587, 'ssl' for port 465
    'username'   => 'YOUR_EMAIL@gmail.com',      // the school email that sends the messages
    'password'   => 'YOUR_APP_PASSWORD',         // Gmail App Password (16 letters)
    'from_email' => 'YOUR_EMAIL@gmail.com',
    'from_name'  => 'Zika Foundation Schools',

    // Address teachers should open. Leave '' to detect automatically.
    // If teachers use their own phones/computers, put the school computer's address here,
    // e.g. 'http://192.168.1.10/SCHOOL' (NOT localhost).
    'site_url'   => '',

    // WampServer often has no certificate list, so this is false. Set true if your PHP has one.
    'verify_ssl' => false,
];
