<?php

/**
 * Removes configured protected terms from names only at the Temu XLSX export
 * boundary.  This class deliberately has no WordPress dependencies so it can
 * be tested independently from the admin exporter.
 */
class MG_Temu_Name_Filter {

    const OPTION_KEY = 'mg_temu_protected_name_terms';

    /**
     * Built-in starter list shown and used until the admin saves an override.
     * Header lines are comments so the textarea remains easy to maintain.
     *
     * @return string
     */
    public static function default_terms_text() {
        return <<<'TERMS'
# JÁTÉKOK ÉS JÁTÉKFRANCHISE-OK
Minecraft
Roblox
Fortnite
Pokémon
Pokemon
Nintendo
Super Mario
Mario Kart
The Legend of Zelda
Animal Crossing
Splatoon
Kirby
Sonic the Hedgehog
Sonic
PlayStation
Xbox
Halo
Call of Duty
Assassin's Creed
Assassins Creed
The Last of Us
God of War
Resident Evil
Metal Gear Solid
Street Fighter
Mortal Kombat
Tekken
Overwatch
Valorant
League of Legends
Dota 2
World of Warcraft
Diablo
StarCraft
Counter-Strike
PUBG
Apex Legends
Genshin Impact
Honkai: Star Rail
Honkai Star Rail
Elden Ring
Dark Souls
Bloodborne
Final Fantasy
Kingdom Hearts
The Witcher
Witcher
Cyberpunk 2077
Grand Theft Auto
GTA
Red Dead Redemption
Need for Speed
The Sims
Fallout
Skyrim
The Elder Scrolls
Mass Effect
Minecraft Creeper
Among Us
Five Nights at Freddy's
Five Nights at Freddys
FNAF

# FILM, RAJZFILM ÉS ANIME
Disney
Pixar
Marvel
Marvel Studios
Spider-Man
Spiderman
Pókember
Pokember
Avengers
Bosszúállók
Bosszuallok
Iron Man
Vasember
Captain America
Amerika Kapitány
Amerika Kapitany
Deadpool
Guardians of the Galaxy
Galaxis őrzői
Galaxis orzoi
DC Comics
Batman
Superman
Wonder Woman
Joker
Harley Quinn
Star Wars
The Mandalorian
Mandalorian
Grogu
Baby Yoda
Star Trek
Harry Potter
Hogwarts
The Lord of the Rings
Lord of the Rings
Gyűrűk Ura
Gyuruk Ura
The Hobbit
Hobbit
Jurassic Park
Jurassic World
Transformers
Teenage Mutant Ninja Turtles
TMNT
Hello Kitty
Sanrio
My Little Pony
Barbie
Peppa Pig
SpongeBob SquarePants
SpongeBob
One Piece
Naruto
Boruto
Dragon Ball
Demon Slayer: Kimetsu no Yaiba
Demon Slayer
Attack on Titan
Sailor Moon
Jujutsu Kaisen
My Hero Academia
Death Note
Chainsaw Man
Studio Ghibli
Spirited Away
My Neighbor Totoro
Totoro
Mickey Mouse
Mickey egér
Mickey eger
Minnie Mouse
Minnie egér
Donald Duck
Donald kacsa
Minions
Frozen
Jégvarázs
Jegvarazs
Toy Story
Verdák
Cars Pixar
The Simpsons
South Park
Rick and Morty
Paw Patrol
Mancs őrjárat
Mancs orjarat
Wednesday Addams
Stranger Things
Scooby-Doo
Scooby Doo
Looney Tunes
Tom and Jerry
Tom és Jerry
Tom es Jerry
Winnie the Pooh
Micimackó
Micimacko
Lilo & Stitch
Lilo and Stitch
Stitch Disney

# AUTÓMÁRKÁK
Audi
BMW
Mercedes-Benz
Mercedes Benz
Volkswagen
Škoda
Skoda
Opel
Dacia
Porsche
Ferrari
Lamborghini
Maserati
Bugatti
Bentley
BWM
Rolls-Royce
Land Rover
Range Rover
Tesla
Toyota
Honda
Hyundai
Kia
Nissan
Mazda
Subaru
Mitsubishi
Lexus
Infiniti
Acura
Volvo
Renault
Peugeot
Citroën
Citroen
Fiat
Alfa Romeo
Ford
Chevrolet
Cadillac
Jeep
Dodge
Ram Trucks
Abarth
Cupra
Saab
Aston Martin
McLaren
Koenigsegg
Pagani
Lotus Cars

# MOTORKERÉKPÁR-MÁRKÁK
Harley-Davidson
Harley Davidson
Indian Motorcycle
Ducati
Yamaha
Suzuki
Kawasaki
KTM
Triumph Motorcycles
Royal Enfield
Aprilia
Moto Guzzi
Husqvarna Motorcycles
BMW Motorrad
Honda Motorcycles
Zero Motorcycles
Vespa
Piaggio
Benelli
MV Agusta
CFMoto
Can-Am
Polaris

# SPORT- ÉS DIVATMÁRKÁK
Nike
Adidas
Puma
Reebok
Under Armour
New Balance
Converse
Vans
Air Jordan
Jordan Brand
Fila
Asics
Skechers
Umbro
Kappa
Supreme
Louis Vuitton
Gucci
Prada
Chanel
Dior
Burberry
Versace
Balenciaga
Hermès
Hermes
Fendi
Rolex
Swatch
Levi's
Levi Strauss
The North Face
Patagonia
Champion
Lacoste
Tommy Hilfiger
Calvin Klein
Ralph Lauren
Polo Ralph Lauren
New Era
Timberland
Crocs
UGG
Diesel
Guess
Hugo Boss
Armani
Emporio Armani
Michael Kors
Dolce & Gabbana
Dolce and Gabbana
Yves Saint Laurent
Saint Laurent
Moncler
Stone Island

# TECHNOLÓGIAI MÁRKÁK
Google
Microsoft
Samsung
Sony
LG
Intel
AMD
NVIDIA
Qualcomm
Xiaomi
Huawei
OnePlus
Lenovo
Dell
HP
Canon
Nikon
GoPro
Fitbit
Garmin
Bose
JBL
Logitech
Razer
ASUS
Acer
Meta
Oculus

# TOVÁBBI GYAKORI VÉDJEGYEK ÉS JÁTÉKMÁRKÁK
LEGO
Playmobil
Hot Wheels
Matchbox
Mattel
Hasbro
Nerf
Funko
Funko Pop
Rubik's Cube
Rubiks Cube
Formula 1
Fórmula 1
F1 Racing
UEFA Champions League
Champions League
FIFA World Cup
TERMS;
    }

    /**
     * @return array<int,string>
     */
    public static function default_terms() {
        return self::parse_terms(self::default_terms_text());
    }

    /**
     * Conservative Hungarian case/ending variants. The separator belongs to
     * the optional match, so BMWvel, BMW-vel and BMW vel are all removed as a
     * unit while an unrelated continuation such as Marvelous is protected.
     *
     * @return array<int,string>
     */
    private static function hungarian_suffixes() {
        static $suffixes = null;
        if ($suffixes === null) {
            $suffixes = array(
                'ból', 'ből', 'ról', 'ről', 'tól', 'től',
                'nál', 'nél', 'höz', 'ért', 'ként',
                'bol', 'bel', 'rol', 'rel', 'tol', 'tel',
                'nal', 'nel', 'ert', 'kent',
                'val', 'vel', 'wel', 'ban', 'ben', 'nak', 'nek',
                'hoz', 'hez', 'ra', 're', 'ba', 'be',
                'on', 'en', 'ön', 'at', 'et', 'ot', 'öt',
                'os', 'es', 'ös', 'as', 'ás', 'ok', 'ek', 'ök',
                'ig', 'kor', 'n', 't', 's', 'k',
            );
            usort($suffixes, function ($left, $right) {
                return strlen($right) <=> strlen($left);
            });
        }
        return $suffixes;
    }

    /**
     * Convert a textarea value (or an array of values) into unique terms.
     *
     * @param string|array<int,mixed> $raw
     * @return array<int,string>
     */
    public static function parse_terms($raw) {
        if (is_array($raw)) {
            $lines = array();
            foreach ($raw as $value) {
                $lines = array_merge($lines, preg_split('/\r\n|\r|\n/', (string) $value));
            }
        } else {
            $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
        }

        $terms = array();
        $seen = array();
        foreach ((array) $lines as $line) {
            $term = trim((string) $line);
            if ($term === '' || strpos($term, '#') === 0) {
                continue;
            }

            // Duplicates differing only in case do not need another pattern.
            $key = self::lowercase($term);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $terms[] = $term;
        }

        return $terms;
    }

    /**
     * Remove configured literal terms from a name.
     *
     * Matching is case-insensitive and Unicode-aware when the server's PCRE
     * supports it. The surrounding alphanumeric boundary check prevents a
     * term such as "Marvel" from changing "Marvelous".
     *
     * @param string $name
     * @param string|array<int,mixed> $terms
     * @return string
     */
    public static function filter($name, $terms) {
        $original = (string) $name;
        $terms = self::parse_terms($terms);
        if (empty($terms) || $original === '') {
            return $original;
        }

        // Longer alternatives first avoids a short configured term consuming
        // the beginning of a longer phrase on the same name.
        usort($terms, function ($left, $right) {
            return strlen($right) <=> strlen($left);
        });

        $filtered = self::remove_with_unicode_regex($original, $terms);
        if ($filtered === null) {
            $filtered = self::remove_without_unicode_regex($original, $terms);
        }

        $filtered = self::cleanup($filtered);
        // A protected term may be the complete product name. Never hand the
        // XLSX writer an empty Product Name; retain the source name instead.
        return $filtered !== '' ? $filtered : $original;
    }

    /**
     * Alias with an explicit name for callers that prefer a verb-like API.
     *
     * @param string $name
     * @param string|array<int,mixed> $terms
     * @return string
     */
    public static function filter_name($name, $terms) {
        return self::filter($name, $terms);
    }

    /**
     * @param string $name
     * @param array<int,string> $terms
     * @return string|null Null means the Unicode pattern was unavailable or
     * invalid for the input and the fallback remover should be used.
     */
    private static function remove_with_unicode_regex($name, array $terms) {
        $alternatives = array();
        foreach ($terms as $term) {
            $alternatives[] = preg_quote($term, '/');
        }

        $suffix_alternatives = array();
        foreach (self::hungarian_suffixes() as $suffix) {
            $suffix_alternatives[] = preg_quote($suffix, '/');
        }
        $suffix_pattern = '(?:[\s-]*(?:' . implode('|', $suffix_alternatives) . '))?';
        $pattern = '/(?<![\p{L}\p{N}\p{M}])(?:' . implode('|', $alternatives) . ')' . $suffix_pattern . '(?![\p{L}\p{N}\p{M}])/iu';
        $result = @preg_replace($pattern, '', $name);
        return $result === null ? null : $result;
    }

    /**
     * A small fallback for unusual servers without usable Unicode PCRE.
     * mb_stripos keeps case-insensitive matching useful for accented text.
     *
     * @param string $name
     * @param array<int,string> $terms
     * @return string
     */
    private static function remove_without_unicode_regex($name, array $terms) {
        $filtered = $name;
        foreach ($terms as $term) {
            if (function_exists('mb_stripos')) {
                $filtered = self::remove_with_mb_stripos($filtered, $term);
            } else {
                $filtered = self::remove_with_ascii_stripos($filtered, $term);
            }
        }
        return $filtered;
    }

    /**
     * @param string $name
     * @param string $term
     * @return string
     */
    private static function remove_with_mb_stripos($name, $term) {
        $offset = 0;
        $term_length = mb_strlen($term, 'UTF-8');
        if ($term_length < 1) {
            return $name;
        }

        while (($position = mb_stripos($name, $term, $offset, 'UTF-8')) !== false) {
            $before = $position > 0 ? mb_substr($name, $position - 1, 1, 'UTF-8') : '';
            $suffix_length = self::suffix_length_mb($name, $position + $term_length);
            $removed_length = $term_length + $suffix_length;
            $after = mb_substr($name, $position + $removed_length, 1, 'UTF-8');
            if (!self::is_alphanumeric($before) && (!self::is_alphanumeric($after) || $suffix_length > 0)) {
                $remaining_length = mb_strlen($name, 'UTF-8') - ($position + $removed_length);
                $remaining = $remaining_length > 0
                    ? mb_substr($name, $position + $removed_length, $remaining_length, 'UTF-8')
                    : '';
                $name = mb_substr($name, 0, $position, 'UTF-8') . $remaining;
                continue;
            }
            $offset = $position + max(1, $term_length);
        }

        return $name;
    }

    /**
     * @param string $name
     * @param string $term
     * @return string
     */
    private static function remove_with_ascii_stripos($name, $term) {
        $offset = 0;
        $term_length = strlen($term);
        if ($term_length < 1) {
            return $name;
        }

        while (($position = stripos($name, $term, $offset)) !== false) {
            $before = $position > 0 ? $name[$position - 1] : '';
            $suffix_length = self::suffix_length_ascii($name, $position + $term_length);
            $after_position = $position + $term_length + $suffix_length;
            $after = $after_position < strlen($name) ? $name[$after_position] : '';
            if (!self::is_alphanumeric($before) && (!self::is_alphanumeric($after) || $suffix_length > 0)) {
                $name = substr($name, 0, $position) . substr($name, $after_position);
                continue;
            }
            $offset = $position + max(1, $term_length);
        }

        return $name;
    }

    /**
     * Return the number of Unicode characters occupied by an optional
     * Hungarian suffix, including its spaces/hyphens, after a term.
     *
     * @param string $name
     * @param int $start
     * @return int
     */
    private static function suffix_length_mb($name, $start) {
        $name_length = mb_strlen($name, 'UTF-8');
        $suffix_start = $start;
        while ($suffix_start < $name_length && self::is_separator_character(mb_substr($name, $suffix_start, 1, 'UTF-8'))) {
            $suffix_start++;
        }

        foreach (self::hungarian_suffixes() as $suffix) {
            $suffix_length = mb_strlen($suffix, 'UTF-8');
            if (mb_stripos($name, $suffix, $suffix_start, 'UTF-8') !== $suffix_start) {
                continue;
            }
            $after = mb_substr($name, $suffix_start + $suffix_length, 1, 'UTF-8');
            if (!self::is_alphanumeric($after)) {
                return $suffix_start + $suffix_length - $start;
            }
        }

        return 0;
    }

    /**
     * @param string $name
     * @param int $start
     * @return int
     */
    private static function suffix_length_ascii($name, $start) {
        $name_length = strlen($name);
        $suffix_start = $start;
        while ($suffix_start < $name_length && self::is_separator_character($name[$suffix_start])) {
            $suffix_start++;
        }

        foreach (self::hungarian_suffixes() as $suffix) {
            $suffix_length = strlen($suffix);
            if (strncasecmp(substr($name, $suffix_start, $suffix_length), $suffix, $suffix_length) !== 0) {
                continue;
            }
            $after_position = $suffix_start + $suffix_length;
            $after = $after_position < $name_length ? $name[$after_position] : '';
            if (!self::is_alphanumeric($after)) {
                return $suffix_start + $suffix_length - $start;
            }
        }

        return 0;
    }

    /**
     * @param string $value
     * @return bool
     */
    private static function is_separator_character($value) {
        if ($value === '-') {
            return true;
        }
        return $value !== '' && @preg_match('/^\s$/u', $value) === 1;
    }

    /**
     * Normalize whitespace and the spacing left around punctuation by a
     * removed term. This intentionally does not rewrite the remaining words.
     *
     * @param string $value
     * @return string
     */
    private static function cleanup($value) {
        $raw_value = (string) $value;
        $value = preg_replace('/\s+/u', ' ', $raw_value);
        if ($value === null) {
            $value = preg_replace('/\s+/', ' ', $raw_value);
        }
        $value = trim((string) $value);

        $value = preg_replace('/\s+([,.;:!?%])/', '$1', $value);
        $value = preg_replace('/([([{])\s+/', '$1', $value);
        $value = preg_replace('/\s+([)\]}])/', '$1', $value);
        // A removed item can leave two adjacent dash separators. Keep one,
        // with conventional single spacing around it.
        $value = preg_replace('/\s*([-–—])(?:\s*[-–—])+\s*/u', ' $1 ', $value);
        // Do not leave a separator stranded at the edge of a name.
        $value = preg_replace('/^(?:[-–—|,:;\/]+\s*)+|(?:\s*[-–—|,:;\/]+)+$/u', '', $value);

        return trim((string) $value);
    }

    /**
     * @param string $value
     * @return string
     */
    private static function lowercase($value) {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * @param string $value
     * @return bool
     */
    private static function is_alphanumeric($value) {
        if ($value === '') {
            return false;
        }
        $unicode = @preg_match('/^[\p{L}\p{N}\p{M}]$/u', $value);
        if ($unicode === 1) {
            return true;
        }
        return preg_match('/^[A-Za-z0-9]$/', $value) === 1;
    }
}
