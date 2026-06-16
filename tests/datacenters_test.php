<?php

require __DIR__ . '/assert.php';
require __DIR__ . '/../lib/Datacenters.php';

use OvhVps\Datacenters;

// --- label(): known codes map to "City, Country" (English) ---
check('GRA -> Gravelines', Datacenters::label('GRA'), 'Gravelines, France');
check('SBG -> Strasbourg', Datacenters::label('SBG'), 'Strasbourg, France');
check('WAW -> Warsaw', Datacenters::label('WAW'), 'Warsaw, Poland');
check('BHS -> Beauharnois', Datacenters::label('BHS'), 'Beauharnois, Canada');
check('UK -> London', Datacenters::label('UK'), 'London, United Kingdom');
check('YNM -> Mumbai', Datacenters::label('YNM'), 'Mumbai, India');
check('SGP -> Singapore', Datacenters::label('SGP'), 'Singapore');

// --- label(): case-insensitive and whitespace-tolerant lookup ---
check('lowercase code matches', Datacenters::label('gra'), 'Gravelines, France');
check('mixed case matches', Datacenters::label('Waw'), 'Warsaw, Poland');
check('surrounding whitespace trimmed', Datacenters::label('  YNM  '), 'Mumbai, India');

// --- label(): unknown code falls back to the raw code (trimmed), never blank ---
check('unknown code -> raw fallback', Datacenters::label('ZZZ'), 'ZZZ');
check('unknown keeps original case', Datacenters::label('newdc'), 'newdc');
check('unknown trimmed', Datacenters::label('  NEW  '), 'NEW');
check('empty stays empty', Datacenters::label(''), '');

done();
