<?php

namespace App\Services\Search;

class KeywordDictionary
{
    public static function getVehicleTypePatterns()
    {
        return [
            'atv' => [
                'keywords' => ['atv', 'quad', 'four wheeler', 'all terrain vehicle', 'all-terrain', '4-wheeler'],
                'weight' => 100,
                'negative' => ['utvs', 'utv parts']
            ],
            'utv' => [
                'keywords' => ['utv', 'side by side', 'side-by-side', 'sxs', 's x s', 'utility vehicle', 'utility terrain'],
                'weight' => 100,
                'negative' => []
            ],
            'dirt bike' => [
                'keywords' => ['dirt bike', 'dirtbike', 'mx', 'motocross', 'enduro', 'off-road bike', 'offroad bike', 'off road bike'],
                'weight' => 100,
                'negative' => ['mountain bike', 'bicycle']
            ],
            'motorcycle' => [
                'keywords' => ['motorcycle', 'street bike', 'cruiser', 'sportbike', 'sport bike', 'touring bike', 'touring motorcycle', 'road bike'],
                'weight' => 100,
                'negative' => ['dirt bike', 'bicycle', 'mountain bike']
            ],
            'scooter' => [
                'keywords' => ['scooter', 'moped'],
                'weight' => 100,
                'negative' => []
            ],
            'e-bike' => [
                'keywords' => ['e-bike', 'ebike', 'e bike', 'electric bike'],
                'weight' => 100,
                'negative' => ['mountain bike', 'bicycle']
            ],
            'watercraft' => [
                'keywords' => ['pwc', 'jet ski', 'jetski', 'watercraft', 'sea-doo', 'seadoo', 'wave runner', 'waverunner'],
                'weight' => 100,
                'negative' => []
            ],
            'snowmobile' => [
                'keywords' => ['snowmobile', 'snow mobile', 'sled', 'ski-doo', 'skidoo'],
                'weight' => 100,
                'negative' => []
            ]
        ];
    }

    public static function getVehicleBrandPatterns()
    {
        return [
            'honda' => ['honda', 'hon', 'hm'],
            'yamaha' => ['yamaha', 'yam'],
            'kawasaki' => ['kawasaki', 'kawi', 'kaw'],
            'suzuki' => ['suzuki', 'suz'],
            'polaris' => ['polaris', 'pol'],
            'can-am' => ['can-am', 'canam', 'can am', 'brp'],
            'arctic cat' => ['arctic cat', 'arcticcat'],
            'ktm' => ['ktm'],
            'husqvarna' => ['husqvarna', 'husky', 'husq'],
            'beta' => ['beta'],
            'gas gas' => ['gas gas', 'gasgas', 'gas-gas'],
            'sherco' => ['sherco'],
            'cfmoto' => ['cfmoto', 'cf moto', 'cf-moto'],
            'kymco' => ['kymco'],
            'sym' => ['sym'],
            'aprilia' => ['aprilia'],
            'ducati' => ['ducati'],
            'triumph' => ['triumph'],
            'harley' => ['harley', 'harley-davidson', 'harley davidson', 'h-d'],
            'indian' => ['indian motorcycle', 'indian'],
            'bmw' => ['bmw'],
            'kawasaki mule' => ['mule'],
            'polaris rzr' => ['rzr'],
            'polaris ranger' => ['ranger'],
            'can-am maverick' => ['maverick'],
            'can-am defender' => ['defender'],
            'honda pioneer' => ['pioneer'],
            'honda talon' => ['talon'],
            'yamaha rhino' => ['rhino'],
            'yamaha wolverine' => ['wolverine']
        ];
    }

    public static function getPartBrandPatterns()
    {
        return [
            'dunlop' => ['dunlop'],
            'michelin' => ['michelin'],
            'bridgestone' => ['bridgestone'],
            'pirelli' => ['pirelli'],
            'metzeler' => ['metzeler'],
            'continental' => ['continental'],
            'shinko' => ['shinko'],
            'kenda' => ['kenda'],
            'maxxis' => ['maxxis'],
            'itp' => ['itp'],
            'sedona' => ['sedona'],
            'carlisle' => ['carlisle'],
            'duro' => ['duro'],
            'cst' => ['cst'],
            'cheng shin' => ['cheng shin'],
            'kymco' => ['kymco'],
            'ngk' => ['ngk'],
            'denso' => ['denso'],
            'bosch' => ['bosch'],
            'champion' => ['champion'],
            'k&n' => ['k&n', 'k & n'],
            'hiflofiltro' => ['hiflofiltro', 'hiflo'],
            'motul' => ['motul'],
            'castrol' => ['castrol'],
            'mobil' => ['mobil'],
            'valvoline' => ['valvoline'],
            'maxima' => ['maxima'],
            'bel-ray' => ['bel-ray', 'belray', 'bel ray'],
            'renthal' => ['renthal'],
            'pro taper' => ['pro taper', 'protaper'],
            'motion pro' => ['motion pro'],
            'tusk' => ['tusk'],
            'moose' => ['moose'],
            'wiseco' => ['wiseco'],
            'vertex' => ['vertex'],
            'prox' => ['prox'],
            'hot rods' => ['hot rods', 'hotrods'],
            'all balls' => ['all balls', 'allballs'],
            'ebc' => ['ebc'],
            'galfer' => ['galfer'],
            'braking' => ['braking'],
            'dp brakes' => ['dp brakes'],
            'sbs' => ['sbs'],
            'dynojet' => ['dynojet'],
            'fmf' => ['fmf'],
            'yoshimura' => ['yoshimura'],
            'akrapovic' => ['akrapovic'],
            'two brothers' => ['two brothers'],
            'pro circuit' => ['pro circuit'],
            'works connection' => ['works connection']
        ];
    }

    public static function getPartCategoryPatterns()
    {
        return [
            'tire' => [
                'keywords' => ['tire', 'tires', 'tyre', 'tyres'],
                'weight' => 90,
                'negative' => ['tire iron', 'tire gauge', 'tire pressure', 'tire repair kit', 'tire tool', 'tire changer', 'tire lever', 'tire display', 'tire stand', 'tire rack', 'tire hook', 'tire holder', 'for tire', 'tire insert', 'tire inserts', 'foam tire', 'of tires', 'of tire', 'with tire', 'with tires', 'installation of tire', 'allows tire', 'for tires', 'extra-wide tire', 'wide tire', 'wider tire', 'tube', 'tubes', 'inner tube', 'inner tubes']
            ],
            'wheel' => [
                'keywords' => ['wheel', 'wheels', 'rim', 'rims'],
                'weight' => 90,
                'negative' => []
            ],
            'brake' => [
                'keywords' => ['brake', 'brakes', 'braking'],
                'weight' => 85,
                'negative' => []
            ],
            'filter' => [
                'keywords' => ['filter', 'filters'],
                'weight' => 80,
                'negative' => []
            ],
            'oil' => [
                'keywords' => ['oil', 'lubricant', 'lube'],
                'weight' => 80,
                'negative' => ['oil filter', 'oil pan', 'oil pump', 'oil cooler', 'oil tank', 'oil line', 'foil', 'oil gauge', 'oil pressure', 'oil temperature', 'for oil', 'coil']
            ],
            'exhaust' => [
                'keywords' => ['exhaust', 'muffler', 'pipe', 'silencer'],
                'weight' => 85,
                'negative' => []
            ],
            'suspension' => [
                'keywords' => ['suspension', 'shock', 'shocks', 'fork', 'forks', 'spring', 'springs'],
                'weight' => 85,
                'negative' => []
            ],
            'engine' => [
                'keywords' => ['engine', 'motor', 'piston', 'cylinder', 'crankshaft', 'camshaft'],
                'weight' => 85,
                'negative' => []
            ],
            'electrical' => [
                'keywords' => ['electrical', 'battery', 'starter', 'alternator', 'stator', 'ignition', 'spark plug'],
                'weight' => 80,
                'negative' => []
            ],
            'body' => [
                'keywords' => ['body', 'plastics', 'fender', 'fenders', 'panel', 'panels', 'shroud', 'shrouds'],
                'weight' => 75,
                'negative' => []
            ],
            'windshield' => [
                'keywords' => ['windshield', 'windscreen', 'wind screen'],
                'weight' => 90,
                'negative' => ['windshield wiper', 'windshield washer', 'windshield cleaner', 'windshield polish', 'windshield cloth', 'windshield defroster', 'for windshield', 'windshield bag', 'windshield strap', 'hard top', 'aluminum top', 'cab enclosure', 'roof', 'roofs', 'cargo box mounted']
            ],
            'door' => [
                'keywords' => ['door', 'doors'],
                'weight' => 85,
                'negative' => []
            ],
            'roof' => [
                'keywords' => ['roof', 'top', 'canopy'],
                'weight' => 85,
                'negative' => []
            ],
            'seat' => [
                'keywords' => ['seat', 'seats', 'seat cover'],
                'weight' => 80,
                'negative' => []
            ],
            'rack' => [
                'keywords' => ['rack', 'racks', 'cargo rack', 'luggage rack'],
                'weight' => 80,
                'negative' => []
            ],
            'winch' => [
                'keywords' => ['winch'],
                'weight' => 85,
                'negative' => []
            ],
            'plow' => [
                'keywords' => ['plow', 'snow plow', 'blade'],
                'weight' => 85,
                'negative' => []
            ],
            'skid plate' => [
                'keywords' => ['skid plate', 'skidplate', 'skid-plate', 'belly pan', 'underbody protection'],
                'weight' => 85,
                'negative' => []
            ],
            'bumper' => [
                'keywords' => ['bumper', 'bumpers'],
                'weight' => 80,
                'negative' => []
            ],
            'mirror' => [
                'keywords' => ['mirror', 'mirrors'],
                'weight' => 75,
                'negative' => []
            ],
            'light' => [
                'keywords' => ['light', 'lights', 'lighting', 'led', 'headlight', 'tail light'],
                'weight' => 80,
                'negative' => []
            ],
            'chain' => [
                'keywords' => ['chain', 'sprocket', 'sprockets'],
                'weight' => 80,
                'negative' => ['chain lube']
            ],
            'clutch' => [
                'keywords' => ['clutch'],
                'weight' => 85,
                'negative' => []
            ],
            'belt' => [
                'keywords' => ['belt', 'drive belt', 'cvt belt'],
                'weight' => 85,
                'negative' => []
            ],
            'carburetor' => [
                'keywords' => ['carburetor', 'carb', 'carburator'],
                'weight' => 85,
                'negative' => []
            ],
            'fuel system' => [
                'keywords' => ['fuel pump', 'fuel line', 'fuel filter', 'fuel tank', 'fuel system'],
                'weight' => 80,
                'negative' => []
            ],
            'radiator' => [
                'keywords' => ['radiator', 'cooling system', 'coolant'],
                'weight' => 80,
                'negative' => []
            ],
            'axle' => [
                'keywords' => ['axle', 'axles', 'cv axle', 'drive axle'],
                'weight' => 85,
                'negative' => []
            ],
            'bearing' => [
                'keywords' => ['bearing', 'bearings'],
                'weight' => 75,
                'negative' => []
            ],
            'gasket' => [
                'keywords' => ['gasket', 'gaskets', 'seal', 'seals'],
                'weight' => 75,
                'negative' => []
            ],
            'helmet' => [
                'keywords' => ['helmet', 'helmets'],
                'weight' => 90,
                'negative' => ['helmet bag', 'helmet lock', 'helmet holder', 'helmet strap', 'helmet mount', 'helmet cam', 'helmet camera', 'for helmet', 'helmet visor', 'helmet polish', 'helmet cloth', 'helmet speaker', 'helmet microphone', 'helmet bluetooth', 'helmet defroster']
            ],
            'gloves' => [
                'keywords' => ['gloves', 'glove'],
                'weight' => 85,
                'negative' => []
            ],
            'jacket' => [
                'keywords' => ['jacket', 'jackets'],
                'weight' => 85,
                'negative' => []
            ],
            'pants' => [
                'keywords' => ['pants', 'riding pants', 'moto pants'],
                'weight' => 85,
                'negative' => []
            ],
            'boots' => [
                'keywords' => ['boots', 'boot', 'riding boots', 'moto boots'],
                'weight' => 85,
                'negative' => []
            ],
            'goggles' => [
                'keywords' => ['goggles', 'goggle'],
                'weight' => 85,
                'negative' => ['goggle bag', 'goggle case']
            ],
            'cargo' => [
                'keywords' => ['cargo', 'cargo box', 'cargo rack', 'storage box'],
                'weight' => 80,
                'negative' => []
            ],
            'protection' => [
                'keywords' => ['protection', 'armor', 'guard', 'chest protector', 'body armor'],
                'weight' => 85,
                'negative' => []
            ],
            'hydration' => [
                'keywords' => ['hydration', 'hydration pack', 'water pack'],
                'weight' => 80,
                'negative' => []
            ],
            'luggage' => [
                'keywords' => ['luggage', 'saddlebag', 'tail bag', 'tank bag'],
                'weight' => 80,
                'negative' => []
            ],
            'tool' => [
                'keywords' => ['tool', 'tools', 'tool kit'],
                'weight' => 75,
                'negative' => []
            ],
            'maintenance kit' => [
                'keywords' => ['maintenance kit', 'service kit'],
                'weight' => 85,
                'negative' => []
            ],
            'cvt' => [
                'keywords' => ['cvt', 'cvt belt', 'continuously variable'],
                'weight' => 85,
                'negative' => []
            ],
            'big bore' => [
                'keywords' => ['big bore', 'big bore kit', 'cylinder kit'],
                'weight' => 90,
                'negative' => []
            ],
            'lift kit' => [
                'keywords' => ['lift kit', 'suspension lift'],
                'weight' => 85,
                'negative' => []
            ],
            'guard' => [
                'keywords' => ['handguard', 'hand guard', 'brush guard', 'knee guard', 'elbow guard'],
                'weight' => 85,
                'negative' => []
            ],
            'ecu' => [
                'keywords' => ['ecu', 'ecu tuner', 'fuel controller', 'fuel management', 'power commander'],
                'weight' => 90,
                'negative' => []
            ],
            'charger' => [
                'keywords' => ['battery charger', 'charger', 'battery tender', 'trickle charger'],
                'weight' => 80,
                'negative' => []
            ]
        ];
    }

    public static function getFeaturePatterns()
    {
        return [
            'oem' => ['oem', 'original equipment', 'factory', 'genuine'],
            'aftermarket' => ['aftermarket', 'after market', 'replacement'],
            'high performance' => ['high performance', 'racing performance', 'increased performance'],
            'heavy-duty' => ['heavy-duty', 'heavy duty', 'hd', 'reinforced'],
            'cheap' => ['cheap', 'budget', 'affordable', 'discount', 'economy'],
            'premium' => ['premium', 'pro', 'professional', 'high-end'],
            'upgraded' => ['upgrade', 'upgraded', 'enhanced'],
            'complete' => ['complete', 'kit', 'set', 'package'],
            'universal' => ['universal', 'generic'],
            'fast-shipping' => ['fast shipping', 'quick ship', 'same day'],
            'online' => ['online', 'buy online'],
            'oversized' => ['oversized', 'over sized'],
            'mud' => ['mud', 'mud tire', 'mud terrain'],
            'adventure' => ['adventure', 'touring', 'adv'],
            'sport' => ['sport', 'racing', 'race']
        ];
    }

    public static function getApplicationPatterns()
    {
        return [
            'fits' => '/fits?\s+([a-z0-9\-\s]+)/i',
            'for' => '/for\s+([a-z0-9\-\s]+)/i',
            'compatible' => '/compatible\s+with\s+([a-z0-9\-\s]+)/i',
            'works with' => '/works?\s+with\s+([a-z0-9\-\s]+)/i',
            'designed for' => '/designed\s+for\s+([a-z0-9\-\s]+)/i'
        ];
    }

    public static function getVehicleModelPatterns()
    {
        return [
            'honda trx' => '/honda\s+trx\s*[\d]+/i',
            'honda crf' => '/honda\s+crf\s*[\d]+/i',
            'honda pioneer' => '/pioneer\s+[\d]+/i',
            'honda talon' => '/talon\s+[\d]+/i',
            'yamaha yfz' => '/yamaha\s+yfz\s*[\d]+/i',
            'yamaha raptor' => '/raptor\s+[\d]+/i',
            'yamaha rhino' => '/rhino\s+[\d]+/i',
            'yamaha wolverine' => '/wolverine\s+[\d]+/i',
            'kawasaki mule' => '/mule\s+[\d]+/i',
            'kawasaki brute force' => '/brute\s+force\s+[\d]+/i',
            'kawasaki kx' => '/kx\s*[\d]+/i',
            'polaris rzr' => '/rzr\s+[\d]+/i',
            'polaris ranger' => '/ranger\s+[\d]+/i',
            'polaris sportsman' => '/sportsman\s+[\d]+/i',
            'can-am maverick' => '/maverick\s+[\w\d]+/i',
            'can-am defender' => '/defender\s+[\w\d]+/i',
            'can-am outlander' => '/outlander\s+[\d]+/i',
            'suzuki king quad' => '/king\s+quad\s+[\d]+/i',
            'suzuki quadsport' => '/quadsport\s+[\d]+/i',
            'arctic cat wildcat' => '/wildcat\s+[\d]+/i',
            'ktm duke' => '/duke\s+[\d]+/i',
            'ktm adventure' => '/adventure\s+[\d]+/i'
        ];
    }

    public static function normalizeKeyword($keyword)
    {
        $keyword = strtolower(trim($keyword));
        $keyword = preg_replace('/[^a-z0-9\s\-]/', '', $keyword);
        $keyword = preg_replace('/\s+/', ' ', $keyword);
        return $keyword;
    }

    public static function isNegativeMatch($text, $negativePatterns)
    {
        if (empty($negativePatterns)) {
            return false;
        }

        $lowerText = strtolower($text);
        foreach ($negativePatterns as $pattern) {
            if (strpos($lowerText, strtolower($pattern)) !== false) {
                return true;
            }
        }

        return false;
    }
}
