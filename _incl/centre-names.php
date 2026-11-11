<?php
// Friendly (short) centre names, keyed by Kidsoft ServiceID. Kidsoft's full names
// carry the brand + descriptors ("Little Miracles Preschool & Long Daycare
// Tuggerah"); staff only need the suburb ("Tuggerah").
//
// Only ACTIVE services are listed. The seeder falls back to stripping the brand
// prefix when a ServiceID is missing here - add a row to override a new centre
// or fix a derivation that guesses wrong.

return [
    'f25e9387-eb46-43a6-a22a-a6d456e49a4d' => 'Kanwal',
    'f802b743-8f2c-4ead-b040-cb7f44e411af' => 'Niagara Park',
    '06190d1c-7245-4b34-98d9-90b00060fb63' => 'Point Clare',
    '5c42b0c0-a2ed-4ee1-a525-b16e6983c2ee' => 'Tuggerah',
    '586cd037-2423-4fdc-8ec2-783b738abf23' => 'Terrigal',
    '52d36490-2ef6-4690-8c2a-de79ae0cbd1f' => 'Wamberal',
    '2650786d-07a3-4900-8b8f-ed7c0aa7a95f' => 'Warnervale',
];
