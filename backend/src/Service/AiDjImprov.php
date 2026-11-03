<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AiDj;
use App\Entity\Station;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;

/**
 * On-the-fly lines for a DJ: things that are not in the content library. Each
 * line is put together at air time from what is happening right now (the hour,
 * the day, the season, the song that just played, the Bible book last read) and
 * small building blocks joined by rules that keep them making sense, so the
 * same line almost never comes up twice. No outside service is used.
 */
final class AiDjImprov
{
    /** How many recent lines per DJ are remembered so they are not repeated. */
    private const int RECENT_LIMIT = 80;
    private const int RECENT_TTL = 172800;

    private const int MAX_CHARS = 300;

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    /** Remember the Bible book a DJ just read, so a later line can mention it. */
    public function rememberVerse(AiDj $dj, ?string $reference): void
    {
        if (null !== $reference && '' !== trim($reference)) {
            $this->cache->set('ai_dj_improv_verse_' . $dj->getId(), trim($reference), 7200);
        }
    }

    /**
     * One fresh spoken line, or null when nothing new could be made.
     */
    public function improvise(AiDj $dj, Station $station): ?string
    {
        $context = $this->context($dj, $station);
        $recentKey = 'ai_dj_improv_recent_' . $dj->getId();
        $recent = $this->cache->get($recentKey);
        $recent = is_array($recent) ? $recent : [];

        $line = null;
        for ($try = 0; null === $line && $try < 10; $try++) {
            $candidate = self::clean($this->compose($context));
            if (null !== $candidate && !in_array(md5($candidate), $recent, true)) {
                $line = $candidate;
            }
        }

        if (null === $line) {
            return null;
        }

        $recent[] = md5($line);
        $this->cache->set($recentKey, array_slice($recent, -self::RECENT_LIMIT), self::RECENT_TTL);

        return $line;
    }

    /**
     * @return array{hour: int, minute: int, weekday: string, month: int, day: int,
     *     artist: ?string, title: ?string, verse: ?string, station: string, dj: string}
     */
    private function context(AiDj $dj, Station $station): array
    {
        $now = new DateTimeImmutable('now', $station->getTimezoneObject());
        $song = $this->cache->get('ai_dj_prev_song_' . $station->id);
        $verse = $this->cache->get('ai_dj_improv_verse_' . $dj->getId());

        return [
            'hour' => (int)$now->format('G'),
            'minute' => (int)$now->format('i'),
            'weekday' => $now->format('l'),
            'month' => (int)$now->format('n'),
            'day' => (int)$now->format('j'),
            'artist' => is_array($song) ? ($song['artist'] ?? null) : null,
            'title' => is_array($song) ? ($song['title'] ?? null) : null,
            'verse' => is_string($verse) ? $verse : null,
            'station' => $station->name,
            'dj' => $dj->getName(),
        ];
    }

    // ---------------------------------------------------------------------
    // Built-in composer
    // ---------------------------------------------------------------------

    private function compose(array $c): string
    {
        $modes = [
            'humor' => 20,
            'encourage' => 16,
            'inspire' => 12,
            'bible' => 12,
            'bible_story' => 14,
            'story' => 12,
            'night' => 10,
            'day' => 5,
        ];
        if (null !== $c['title'] && '' !== $c['title']) {
            $modes['song'] = 22;
        }

        return match (self::weighted($modes)) {
            'humor' => $this->humor(),
            'encourage' => $this->encourage(),
            'inspire' => $this->inspire(),
            'bible' => $this->bible($c),
            'bible_story' => $this->bibleStory(),
            'story' => $this->story(),
            'night' => $this->night($c),
            'day' => $this->day($c),
            default => $this->song($c),
        };
    }

    private function humor(): string
    {
        $joke = self::pick(self::JOKES);
        if (1 === mt_rand(0, 2)) {
            return $joke;
        }

        return self::pick(self::JOKE_LEADS) . ' ' . $joke . ' ' . self::pick(self::JOKE_TAGS);
    }

    private function encourage(): string
    {
        // Lines about work only go to listeners who are working right now.
        if (1 === mt_rand(0, 2)) {
            return self::pick(self::WORKERS) . ' ' . self::pick([...self::ENCOURAGE_CORE, ...self::WORK_CORE]) . ' ' . self::pick(self::PROMISE_TAILS);
        }

        return self::pick(self::LISTENERS) . ' ' . self::pick(self::ENCOURAGE_CORE) . ' ' . self::pick(self::PROMISE_TAILS);
    }

    private function inspire(): string
    {
        $line = self::pick(self::INSPIRE_CORE);

        return 1 === mt_rand(0, 1) ? self::pick(self::INSPIRE_LEADS) . ' ' . $line : $line;
    }

    private function bible(array $c): string
    {
        $book = null;
        if (null !== $c['verse'] && preg_match('/^((?:[1-3]\s+)?[A-Za-z][A-Za-z ]*?)\s+\d/', $c['verse'], $m)) {
            $book = self::normalizeBook($m[1]);
        }

        // About a book just read, when we know one.
        if (null !== $book && isset(self::BOOKS[$book]) && 1 === mt_rand(0, 1)) {
            [$chapters, $about] = self::BOOKS[$book];
            $spokenBook = self::spokenBook($book);
            $size = match (true) {
                'Psalms' === $book => 'There are one hundred fifty psalms in all.',
                1 === $chapters => 'The whole book is just one chapter long.',
                default => sprintf('It has %s chapters.', self::number($chapters)),
            };
            $lead = self::pick([
                'That reading earlier came from %s.',
                'We were in %s a little while ago.',
                'I am still thinking about that scripture from %s.',
            ]);

            return sprintf($lead, $spokenBook) . ' ' . $size . ' ' . $about . ' ' . self::pick(self::BIBLE_TAILS);
        }

        if (1 === mt_rand(0, 1)) {
            return self::pick(self::TRIVIA_LEADS) . ' ' . self::pick(self::TRIVIA);
        }

        // A short scripture on a theme.
        $theme = self::pick(array_keys(self::KEYWORD_VERSES));
        [, $ref, $verse] = self::KEYWORD_VERSES[$theme];

        return self::pick(self::VERSE_LEADS) . ' ' . $verse . ' ' . sprintf(self::pick([
            'That is from %s.',
            'You can find that in %s.',
            '%s. Hold on to that one.',
        ]), $ref);
    }

    private function bibleStory(): string
    {
        [$who, $event, $lessons] = self::pick(self::BIBLE_EVENTS);
        $lesson = self::pick([...$lessons, ...$lessons, self::pick(self::STORY_LESSONS)]);

        return match (mt_rand(1, 4)) {
            1 => sprintf('Remember when %s? %s', $event, $lesson),
            2 => sprintf('%s %s. %s', self::pick(self::BIBLE_STORY_LEADS), ucfirst($event), $lesson),
            3 => sprintf('Do you know the story of %s? %s. And here is what I take from it. %s', $who, ucfirst($event), $lesson),
            default => sprintf('I keep thinking about %s tonight. %s. %s', $who, ucfirst($event), $lesson),
        };
    }

    private function story(): string
    {
        [$person, $he, $his] = self::pick(self::STORY_PEOPLE);
        [$problem, $turn, $end] = self::pick(self::STORY_SEEDS);
        $fill = static fn(string $t): string => ucfirst(strtr($t, [
            '{S}' => $person, '{s}' => $he, '{his}' => $his, '{him}' => 'he' === $he ? 'him' : 'her',
        ]));

        return sprintf(
            '%s %s %s %s',
            self::pick(['Picture this.', 'Here is a little story for you.', 'Imagine with me for a minute.', 'Let me paint a picture.']),
            $fill($problem),
            $fill($turn),
            $fill($end)
        );
    }

    private function night(array $c): string
    {
        return self::spokenTime($c['hour'], $c['minute']) . ' ' . self::pick(self::NIGHT_THOUGHTS) . ' ' . self::pick(self::NIGHT_CLOSERS);
    }

    private function day(array $c): string
    {
        $season = self::seasonLine($c['month'], $c['day']);
        $weekday = self::WEEKDAYS[$c['weekday']] ?? null;
        $parts = array_values(array_filter([$weekday, $season]));

        return [] === $parts ? $this->night($c) : self::pick($parts);
    }

    private function song(array $c): string
    {
        $title = (string)$c['title'];
        $artist = $c['artist'];
        $lower = ' ' . strtolower((string)preg_replace('/[^A-Za-z ]+/', ' ', $title)) . ' ';

        $matches = [];
        foreach (self::KEYWORD_VERSES as $theme => [$words]) {
            foreach ($words as $word) {
                if (str_contains($lower, ' ' . $word . ' ')) {
                    $matches[] = $theme;
                    break;
                }
            }
        }

        $frame = null !== $artist && '' !== $artist && 1 === mt_rand(0, 1)
            ? sprintf(self::pick(self::SONG_FRAMES_ARTIST), $title, $artist)
            : sprintf(self::pick(self::SONG_FRAMES), $title);

        if ([] !== $matches) {
            [, $ref, $verse] = self::KEYWORD_VERSES[self::pick($matches)];

            return $frame . ' ' . sprintf(self::pick([
                'It brings to mind %2$s, where it says, %1$s',
                'The Bible says it this way in %2$s. %1$s',
                'Scripture puts it beautifully. %1$s That is %2$s.',
            ]), $verse, $ref);
        }

        return $frame . ' ' . self::pick(self::SONG_THOUGHTS);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Speech-safe, clean text or null. Rejects anything with words that do not
     * belong on a Christian station, and anything too long or oddly formatted.
     */
    public static function clean(string $text): ?string
    {
        $text = trim((string)preg_replace('/\s+/', ' ', strip_tags($text)));
        $text = trim($text, " \"'\u{201C}\u{201D}\u{2018}\u{2019}*");
        $text = str_replace(["\u{2019}", "\u{2018}"], "'", $text);
        $text = str_replace(["\u{201C}", "\u{201D}", '"', '*', '#', '_'], '', $text);
        if ('' === $text || mb_strlen($text) > self::MAX_CHARS || mb_strlen($text) < 20) {
            return null;
        }
        if (!preg_match('/^[\p{L}\p{N}\s.,;:!?\'()-]+$/u', $text)) {
            return null;
        }

        $lower = ' ' . mb_strtolower((string)preg_replace('/[^\p{L}\s]+/u', ' ', $text)) . ' ';
        foreach (self::BLOCKED_WORDS as $word) {
            if (str_contains($lower, ' ' . $word . ' ')) {
                return null;
            }
        }

        if (!preg_match('/[.!?]$/', $text)) {
            $text .= '.';
        }

        return $text;
    }

    private static function spokenTime(int $hour, int $minute): string
    {
        $part = static fn(int $h): string => match (true) {
            $h < 5 => 'in the morning',
            $h < 12 => 'this morning',
            $h < 17 => 'this afternoon',
            $h < 21 => 'this evening',
            default => 'tonight',
        };
        $clock = static fn(int $h): string => self::number($h % 12 ?: 12);
        $next = ($hour + 1) % 24;

        return match (true) {
            $minute < 8 => sprintf("It's just about %s o'clock %s.", $clock($hour), $part($hour)),
            $minute < 23 => sprintf("It's a little after %s %s.", $clock($hour), $part($hour)),
            $minute < 38 => sprintf("It's around half past %s %s.", $clock($hour), $part($hour)),
            default => sprintf("It's almost %s o'clock %s.", $clock($next), $part($next)),
        };
    }

    private static function seasonLine(int $month, int $day): ?string
    {
        return match (true) {
            12 === $month && $day <= 25 => self::pick([
                'We are in the Christmas season, and I love it. The whole world hums carols about a baby in a manger, and many do not even realize they are singing the gospel.',
                'Christmas is coming. In all the rush, remember the first Christmas was quiet. A stable, a few shepherds, and God with us.',
            ]),
            1 === $month && $day <= 7 => 'A new year is still fresh. His mercies are new every morning, and that means a new year is just three hundred sixty five fresh mornings of mercy.',
            11 === $month => self::pick([
                'November is the month of giving thanks. Here is a challenge for the night. Before you fall asleep, name three things God has done for you this year.',
                'The leaves are coming down this time of year. Even the trees remind us that letting go can be an act of trust.',
            ]),
            in_array($month, [3, 4], true) => 'Spring is on the way, and every green shoot is a little sermon about resurrection.',
            in_array($month, [6, 7, 8], true) => 'Summer nights are short, but God is never in a hurry with you. Take your time with Him tonight.',
            in_array($month, [9, 10], true) => 'Autumn is harvest time. Galatians says we reap what we sow, so let us keep sowing kindness, even when nobody seems to notice.',
            default => null,
        };
    }

    private static function normalizeBook(string $book): string
    {
        $book = trim((string)preg_replace('/\s+/', ' ', $book));
        $book = (string)preg_replace('/^Psalm$/i', 'Psalms', $book);

        return ucwords(strtolower($book));
    }

    private static function spokenBook(string $book): string
    {
        return (string)preg_replace_callback(
            '/^([1-3]) /',
            static fn(array $m): string => ['First', 'Second', 'Third'][(int)$m[1] - 1] . ' ',
            'Psalms' === $book ? 'the Book of Psalms' : $book
        );
    }

    private static function number(int $n): string
    {
        static $ones = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
            'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        static $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

        if ($n < 20) {
            return $ones[$n];
        }
        if ($n < 100) {
            return $tens[intdiv($n, 10)] . ($n % 10 ? ' ' . $ones[$n % 10] : '');
        }

        return $ones[intdiv($n, 100)] . ' hundred' . ($n % 100 ? ' ' . self::number($n % 100) : '');
    }

    /** @param array<string, int> $weights */
    private static function weighted(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return (string)array_key_first($weights);
    }

    /**
     * @template T
     * @param array<array-key, T> $items
     * @return T
     */
    private static function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    // ---------------------------------------------------------------------
    // Building blocks
    // ---------------------------------------------------------------------

    private const array BLOCKED_WORDS = [
        'damn', 'hell', 'crap', 'sucks', 'stupid', 'idiot', 'shut up', 'sexy', 'sex', 'drunk', 'beer',
        'gamble', 'casino', 'lottery', 'horoscope', 'luck', 'lucky', 'karma', 'universe', 'manifest', 'vibes',
        'democrat', 'republican', 'liberal', 'conservative', 'election', 'president', 'trump', 'biden',
        'abortion', 'gun', 'guns', 'kill', 'murder', 'war', 'news', 'breaking', 'catholic', 'protestant',
        'baptist', 'methodist', 'pentecostal', 'mormon', 'muslim', 'islam', 'hindu', 'buddha', 'atheist',
        'gay', 'trans', 'dating', 'hot', 'oh my god', 'omg', 'god damn', 'jesus christ almighty',
    ];

    private const array JOKES = [
        'Why could Jonah not trust the ocean? He knew there was something fishy about it.',
        'Who was the greatest comedian in the Bible? Samson. He brought the house down.',
        'What kind of car did the apostles drive? They were all in one Accord.',
        'Who was the best financier in the Bible? Noah. He was floating his stock while the whole world was in liquidation.',
        'Where is tennis first mentioned in the Bible? When Joseph served in Pharaoh\'s court.',
        'Why did they not play cards on the ark? Because Noah was standing on the deck.',
        'Which animal could Noah never trust? The cheetah.',
        'What did Adam say the day before Christmas? It\'s Christmas, Eve.',
        'How do we know Peter was a wealthy fisherman? By his net income.',
        'Why did Moses wander in the desert for forty years? Even back then, men would not stop and ask for directions.',
        'Who was the shortest man in the Bible? Some say Bildad the Shuhite. Others say Nehemiah. Knee high Miah.',
        'How did Adam and Eve feel when they had to leave the garden? Well, they were put out.',
        'What is the best way to study the Bible? You Luke into it.',
        'Why did the choir director get locked out of the church? He had all the keys, from A to G, and none of them fit.',
        'My Bible has so many notes in the margins, the margins are asking for their own margins.',
        'Church potlucks are the only meal where three different casseroles all claim to be the original recipe.',
        'Why are there no secrets in a church kitchen? Too many people stirring.',
        'I asked my coffee to pray for me tonight. It said it already had a full cup of requests.',
        'I have been up so long tonight that my coffee asked me if I needed a break.',
        'My doctor told me to watch my drinking. So now I drink my coffee in front of a mirror.',
        'Our pastor promised a short sermon. He kept that promise. The sermon was short. The introduction, the three points and the closing story were not.',
        'You know you are an overnight DJ when the sunrise feels like a rumor.',
        'Somebody asked me if I talk to myself on the overnight shift. I said no, I talk to you. You just happen to be very quiet.',
        'They say the early bird gets the worm. The overnight bird gets the last donut in the break room.',
        'My grandson asked me what the Internet was like when I was young. I told him we called it the encyclopedia, and it lived on a shelf.',
        'Where does Noah keep his bees? In the ark hives.',
        'What do you call a sheep who knows every hymn by heart? A lamb of praise. I will stop now.',
    ];

    private const array JOKE_LEADS = [
        'All right, a little something to keep you awake.',
        'Here is one for the night owls.',
        'I heard this one at a church fellowship dinner.',
        'Okay, I have to share this.',
        'A friend sent me this one, and I have been waiting all night to tell it.',
        'Time for a smile.',
    ];

    private const array JOKE_TAGS = [
        'I will be here all night. Literally, I am the overnight guy.',
        'Okay, back to the music before I tell another one.',
        'Hey, it is late. They cannot all be winners.',
        'A merry heart doeth good like a medicine. That is Proverbs seventeen, verse twenty two. So that was medicine.',
        'If you groaned, that counts as laughing.',
        'Laughter is a gift from God, and I just regifted it.',
    ];

    private const array LISTENERS = [
        'To the mom or dad up with a little one,',
        'If you are sitting with someone in the hospital tonight,',
        'If you cannot sleep because your mind will not slow down,',
        'If you are lying awake missing someone,',
        'If tonight feels lonelier than most,',
        'To the student still studying,',
        'If you have been praying about the same thing for a long time,',
        'If you are up early getting ready for a long day,',
        'Whoever you are and wherever you are listening,',
        'If you feel like you have let God down,',
        'To the one who is hurting tonight,',
        'If your house is quiet tonight,',
    ];

    private const array WORKERS = [
        'To the nurse on a break right now,',
        'If you are driving through the night,',
        'To whoever is working the late shift,',
        'To the trucker out on the interstate,',
        'To the caregiver who has given all day and still is not done,',
        'To the security guard making rounds,',
        'To the first responders on duty tonight,',
        'To everyone clocked in while the rest of us sleep,',
    ];

    private const array WORK_CORE = [
        'your work matters to God, even when nobody else says thank you.',
        'there is no shift too long for His strength.',
        'Colossians says whatever you do, do it heartily, as to the Lord. He sees every task tonight.',
        'the Lord is your strength for every hour left on this shift.',
    ];

    private const array ENCOURAGE_CORE = [
        'God sees you right where you are.',
        'you are not forgotten. Not for one minute.',
        'the Lord who neither slumbers nor sleeps is keeping watch with you.',
        'the night does not get the final word. The morning is coming.',
        'Jesus is closer than your next breath.',
        'you do not have to carry tomorrow tonight.',
        'God is not tired of hearing from you.',
        'grace is bigger than whatever you are carrying.',
        'the same God who calmed the sea is with you in this.',
        'you are held by hands that have never dropped anyone.',
        'heaven is not silent just because the room is quiet.',
        'your story is not over. God is still writing.',
        'the Good Shepherd knows your name and your road.',
        'you are loved by God on your worst night as much as on your best day.',
    ];

    private const array PROMISE_TAILS = [
        'He said, I will never leave thee, nor forsake thee.',
        'Cast all your care upon Him, for He careth for you.',
        'His mercies are new every morning.',
        'Lean on Him tonight.',
        'Take a deep breath and let Him carry it.',
        'You can tell Him all about it right now.',
        'He has not changed, and He will not let you go.',
        'Rest in that.',
        'That is a promise you can sleep on.',
        'Keep going. He is going with you.',
    ];

    private const array INSPIRE_LEADS = [
        'Here is something I have been thinking about tonight.',
        'A thought for the quiet hours.',
        'Let me share something that has been on my heart.',
        'Something to chew on while the coffee cools.',
    ];

    private const array INSPIRE_CORE = [
        'A small act of kindness done for Jesus is never small in heaven.',
        'Faith is not knowing what tomorrow holds. It is knowing who holds tomorrow.',
        'The cross shows us how much we are worth to God, and the empty tomb shows us how much He can do.',
        'You do not have to see the whole road. You only have to follow the One who does.',
        'Every sunrise is God saying, I am not done yet.',
        'Prayer is not the last thing we do when nothing else works. It is the first thing that changes us.',
        'The hymns we love were written by ordinary people who found an extraordinary God in hard places.',
        'God often does His deepest work in the hours nobody else is watching.',
        'Gratitude turns what we have into enough.',
        'Obedience in small things is where big faith is born.',
        'The Bible is not just a book we read. It is a book that reads us.',
        'Jesus did not come to make bad people good. He came to make dead people alive.',
        'Your quiet faithfulness tonight is a song heaven can hear.',
        'Worry looks at the size of the storm. Faith looks at the size of the Savior.',
        'The empty tomb means every grave is temporary for those who trust in Him.',
        'God does not waste a single tear. He keeps them all, the Psalms tell us.',
    ];

    private const array BIBLE_TAILS = [
        'Maybe pick it up and read a little more tonight.',
        'Every page points us to Jesus.',
        'God\'s Word never runs out of things to say.',
        'Worth a read if you are up anyway.',
    ];

    private const array TRIVIA_LEADS = [
        'Bible trivia for the night owls.',
        'Here is a little Bible fact to take with you.',
        'Did you know this?',
        'Quick one for the Bible lovers out there.',
    ];

    private const array TRIVIA = [
        'The shortest verse in the King James Bible is John eleven, verse thirty five. Jesus wept. Two words that tell us our Savior feels our sorrow.',
        'The longest chapter in the Bible is Psalm one hundred nineteen, with one hundred seventy six verses, and nearly every one of them is about loving God\'s Word.',
        'The shortest chapter in the Bible is Psalm one hundred seventeen. Just two verses, and both of them say praise the Lord.',
        'Methuselah is the oldest man recorded in the Bible. Genesis tells us he lived nine hundred sixty nine years.',
        'Five books of the Bible are only one chapter long. Obadiah, Philemon, Second John, Third John and Jude.',
        'The Bible has sixty six books. Thirty nine in the Old Testament and twenty seven in the New.',
        'The Book of Esther never mentions God by name, yet His hand is on every page of the story.',
        'Jesus\' first miracle in the Gospel of John was turning water into wine at a wedding in Cana.',
        'When Jesus fed the five thousand, the disciples gathered twelve baskets of leftovers. God\'s supply does not just meet the need. It overflows.',
        'David picked up five smooth stones before he faced Goliath. He only needed one.',
        'Genesis says that in Noah\'s day it rained forty days and forty nights. And God kept every soul in the ark safe.',
        'Genesis says Enoch walked with God, and he was not, for God took him. What a way to be remembered.',
        'Zacchaeus climbed a sycamore tree just to see Jesus, and Jesus stopped and called him by name.',
        'Peter actually walked on water. He only began to sink when he looked at the wind instead of at Jesus.',
    ];

    private const array BIBLE_STORY_LEADS = [
        'Here is a Bible story I love.',
        'One of my favorite moments in scripture.',
        'Let me take you back to a story from the Bible.',
        'You know the story.',
    ];

    private const array STORY_LESSONS = [
        'God has not changed. What He did then, He can still do now.',
        'That same Jesus is with you tonight.',
        'The God of that story is the God of your story too.',
        'Never underestimate what God can do with a heart that trusts Him.',
    ];

    /**
     * Bible events: [who, what happened (a clause), lessons].
     */
    private const array BIBLE_EVENTS = [
        ['David', 'young David faced the giant Goliath with a sling and five smooth stones, and God gave him the victory', ['The size of your giant is never bigger than the size of your God.', 'God loves to use ordinary people who simply trust Him.']],
        ['Daniel', 'Daniel kept praying three times a day even though it meant the lions\' den, and God shut the lions\' mouths', ['Faithfulness in prayer is never wasted.', 'God can shut the mouth of whatever is roaring at you tonight.']],
        ['Shadrach, Meshach and Abednego', 'Shadrach, Meshach and Abednego would not bow to the golden image, and in the fiery furnace a fourth man walked with them', ['God does not always keep us out of the fire, but He always walks with us in it.', 'They came out without even the smell of smoke on them.']],
        ['Peter', 'Peter stepped out of the boat and walked on the water toward Jesus, and when he began to sink, Jesus caught him', ['Even when your faith is shaky, His hand is not.', 'Keep your eyes on Jesus, not on the waves.']],
        ['the storm on the sea', 'the disciples woke Jesus in the middle of a storm, and He simply said, Peace, be still, and the wind and the waves obeyed', ['The storms still know His voice.', 'If He is in your boat, the storm does not get the last word.']],
        ['the prodigal son', 'Jesus told about a son who wasted everything and came home, and his father ran to meet him while he was still a great way off', ['No matter how far you have gone, the Father is watching the road for you.', 'Home is always open with God.']],
        ['Zacchaeus', 'Zacchaeus climbed a sycamore tree just to see Jesus, and Jesus stopped, looked up, and called him by name', ['Jesus knows your name too.', 'Nobody is too far out on a limb for Jesus.']],
        ['the loaves and fishes', 'a boy gave Jesus five loaves and two fish, and Jesus fed five thousand men with it, with twelve baskets left over', ['Whatever little you have, put it in His hands.', 'God does not just meet the need. He overflows it.']],
        ['Lazarus', 'Jesus stood at the tomb of His friend Lazarus and wept, and then called him out alive after four days', ['Jesus cares about your grief, and He has power over death.', 'It is never too late for Jesus.']],
        ['the woman who touched His hem', 'a woman who had been sick for twelve years reached out and touched the hem of Jesus\' garment, and she was made whole', ['One touch of faith is enough to reach Him.', 'Jesus noticed her in a crowd, and He notices you.']],
        ['Joseph', 'Joseph was sold by his brothers and thrown into prison, but God raised him up to save many lives', ['What others meant for evil, God meant for good.', 'Your prison season is not the end of your story.']],
        ['Ruth', 'Ruth stayed with her mother in law Naomi and said, whither thou goest, I will go, and God wove her into the family line of King David', ['Faithful love is never forgotten by God.', 'God writes beautiful stories out of sad beginnings.']],
        ['Elijah', 'Elijah sat under a juniper tree so tired he wanted to give up, and God let him sleep and sent an angel with food and water', ['Sometimes the most spiritual thing you can do is get some rest.', 'God is gentle with the weary.']],
        ['the Red Sea', 'the Red Sea parted and Israel walked through on dry ground', ['When there is no way, God makes a way.', 'The God who parted the sea can open a path for you.']],
        ['Noah', 'Noah built the ark long before a drop of rain fell', ['Obedience does not always make sense to others, but it always makes sense to God.', 'Faith gets ready for what God has promised.']],
        ['Gideon', 'God brought Gideon\'s army down to just three hundred men so everyone would know the victory was His', ['God does not need big numbers to do big things.', 'When you feel small, you are right where God can show His strength.']],
        ['Hannah', 'Hannah poured out her heart to God in prayer, and God gave her a son named Samuel', ['God hears the prayers nobody else understands.', 'Pour out your heart to Him. He can take it.']],
        ['Jonah', 'Jonah ran from God, ended up inside a great fish, and still got a second chance', ['God is the God of second chances.', 'You cannot run so far that God cannot reach you.']],
        ['the thief on the cross', 'a thief on the cross asked Jesus to remember him, and Jesus said, To day shalt thou be with me in paradise', ['It is never too late to turn to Jesus.', 'Grace reached a man with nothing left to offer.']],
        ['the empty tomb', 'the women came to the tomb early on Sunday morning and found it empty, because He is risen', ['Because He lives, we can face tomorrow.', 'The greatest message ever told started with an empty tomb.']],
        ['Paul and Silas', 'Paul and Silas sang hymns at midnight in a prison cell, and God shook the doors open', ['Praise in the dark is powerful.', 'Midnight is a good time to sing.']],
        ['the good Samaritan', 'Jesus told of a Samaritan who stopped to help a hurt stranger that others had passed by', ['Sometimes the answer to someone\'s prayer is you stopping to help.', 'Love slows down for the hurting.']],
        ['Esther', 'Queen Esther risked her life to go before the king, and God used her to save her people', ['You may be right where you are for such a time as this.', 'Courage is faith that keeps walking.']],
        ['blind Bartimaeus', 'blind Bartimaeus kept calling out to Jesus even when the crowd told him to be quiet, and Jesus gave him his sight', ['Keep calling out to Jesus. He stops for people like us.', 'Do not let anyone quiet your prayers.']],
        ['Abraham', 'Abraham was an old man when God promised him a son, and God kept that promise', ['God\'s promises do not expire.', 'It is never too late for God to keep His word.']],
        ['Peter\'s fresh start', 'after Peter denied Jesus three times, the risen Lord asked him three times, Lovest thou me, and gave him a fresh start', ['Your failure is not final with Jesus.', 'Jesus restores what we break.']],
        ['the lost sheep', 'Jesus said a good shepherd leaves the ninety and nine to go after the one sheep that is lost', ['If you feel like the one who wandered, He is coming after you.', 'You matter that much to Him.']],
        ['the widow\'s offering', 'a poor widow put two small coins in the offering, and Jesus said she gave more than all the rest', ['God measures the heart, not the amount.', 'Small gifts given with great love are big in heaven.']],
    ];

    /**
     * Everyday story people: [who, he/she, his/her].
     */
    private const array STORY_PEOPLE = [
        ['an old farmer', 'he', 'his'],
        ['a nurse on the night shift', 'she', 'her'],
        ['a grandmother', 'she', 'her'],
        ['a long haul trucker', 'he', 'his'],
        ['a young mom', 'she', 'her'],
        ['a retired pastor', 'he', 'his'],
        ['a college student', 'she', 'her'],
        ['a widower', 'he', 'his'],
        ['a single dad', 'he', 'his'],
        ['a soldier far from home', 'he', 'his'],
        ['a woman waiting on test results', 'she', 'her'],
        ['a man who just lost his job', 'he', 'his'],
    ];

    /**
     * Everyday story seeds: [problem, turn, ending]. Each seed fits any person
     * above, so whoever is picked the story still makes sense.
     */
    private const array STORY_SEEDS = [
        ['{S} is lying awake, worried about tomorrow.', 'Then {s} remembers Jesus saying, take no thought for the morrow, for the morrow shall take thought for the things of itself.', 'Nothing about tomorrow has changed, but {s} has. And {s} finally sleeps.'],
        ['{S} decides to do one small kind thing today without telling anyone.', '{s} leaves a note of encouragement for someone who looks tired.', '{s} will never know that note was the answer to somebody\'s prayer. But God knows.'],
        ['{S} starts writing down one thing to thank God for every night.', 'Some nights it is something big. Some nights it is just a warm cup of coffee.', 'A year later {s} has a whole notebook full of the faithfulness of God.'],
        ['{S} is having a hard day and does not feel like praying.', 'So {s} just hums an old hymn {his} mother used to sing.', 'Halfway through, the hum turns into a prayer. Sometimes the old songs pray for us when we cannot.'],
        ['{S} has carried a grudge for years.', 'One night {s} finally brings it to Jesus and says, Lord, I cannot forgive, but You can, through me.', 'The hurt does not vanish overnight, but the chains do.'],
        ['{S} plants a garden in the spring and checks it every morning.', 'For weeks nothing shows above the ground.', 'But under the soil the roots were growing the whole time. God is working, even when you cannot see it yet.'],
        ['{S} feels alone in a quiet house tonight.', 'So {s} turns on the radio, and the next song says exactly what {his} heart needed to hear.', 'God has a way of finding us wherever we are.'],
        ['{S} has been praying for the same thing for a long time and is tempted to quit asking.', 'Then {s} reads how Jesus taught that we ought always to pray, and not to faint.', 'So {s} keeps praying. Persistent prayer is not nagging God. It is trusting Him.'],
        ['{S} opens an old Bible and finds a verse underlined years ago.', 'Next to it, in faded ink, is a date from the hardest season of {his} life.', 'God carried {him} then, and {s} realizes He is carrying {him} now.'],
        ['{S} is running late, tired, and short on patience.', 'At the gas station, a stranger holds the door and says, God bless you.', 'Three little words, and the whole day feels lighter. Never underestimate a kind word.'],
    ];

    private const array VERSE_LEADS = [
        'Here is a verse I want to leave with you.',
        'Let me give you a little scripture to hold on to.',
        'A word from the Bible for this hour.',
        'This verse has been on my heart tonight.',
    ];

    /**
     * Theme => [words in a song title that point to it, reference, KJV text].
     */
    private const array KEYWORD_VERSES = [
        'grace' => [['grace', 'mercy', 'amazing'], 'Second Corinthians twelve, verse nine', 'My grace is sufficient for thee: for my strength is made perfect in weakness.'],
        'cross' => [['cross', 'calvary', 'tree'], 'Galatians six, verse fourteen', 'God forbid that I should glory, save in the cross of our Lord Jesus Christ.'],
        'home' => [['home', 'heaven', 'mansion', 'mansions', 'glory', 'land', 'shore'], 'John fourteen, verse two', 'In my Father\'s house are many mansions: if it were not so, I would have told you. I go to prepare a place for you.'],
        'river' => [['river', 'water', 'waters', 'fountain', 'well', 'stream'], 'Psalm forty six, verse four', 'There is a river, the streams whereof shall make glad the city of God.'],
        'hills' => [['mountain', 'mountains', 'hill', 'hills', 'valley'], 'Psalm one hundred twenty one', 'I will lift up mine eyes unto the hills, from whence cometh my help. My help cometh from the Lord, which made heaven and earth.'],
        'morning' => [['morning', 'dawn', 'sunrise', 'new', 'faithfulness'], 'Lamentations three', 'His compassions fail not. They are new every morning: great is thy faithfulness.'],
        'love' => [['love', 'loves', 'loved', 'heart'], 'First John four, verse nineteen', 'We love him, because he first loved us.'],
        'peace' => [['peace', 'still', 'calm', 'quiet', 'well'], 'John fourteen, verse twenty seven', 'Peace I leave with you, my peace I give unto you.'],
        'praise' => [['praise', 'hallelujah', 'alleluia', 'sing', 'song', 'bless', 'worship'], 'Psalm one hundred fifty', 'Let every thing that hath breath praise the Lord.'],
        'shepherd' => [['shepherd', 'lamb', 'sheep', 'lead', 'leadeth', 'guide'], 'Psalm twenty three', 'The Lord is my shepherd; I shall not want.'],
        'light' => [['light', 'lamp', 'shine', 'sunshine', 'bright', 'star'], 'Psalm one hundred nineteen, verse one hundred five', 'Thy word is a lamp unto my feet, and a light unto my path.'],
        'night' => [['night', 'dark', 'darkness', 'tears', 'weep'], 'Psalm thirty, verse five', 'Weeping may endure for a night, but joy cometh in the morning.'],
        'storm' => [['storm', 'storms', 'sea', 'wind', 'waves', 'anchor', 'sail'], 'Mark four, verse thirty nine', 'And he arose, and rebuked the wind, and said unto the sea, Peace, be still.'],
        'rock' => [['rock', 'fortress', 'stand', 'solid', 'foundation', 'strong'], 'Psalm eighteen, verse two', 'The Lord is my rock, and my fortress, and my deliverer.'],
        'blood' => [['blood', 'redeemed', 'redeemer', 'washed', 'clean'], 'First John one, verse seven', 'The blood of Jesus Christ his Son cleanseth us from all sin.'],
        'joy' => [['joy', 'joyful', 'happy', 'glad', 'rejoice'], 'Nehemiah eight, verse ten', 'The joy of the Lord is your strength.'],
        'walk' => [['walk', 'walking', 'road', 'journey', 'way', 'follow', 'step', 'steps'], 'Psalm thirty seven, verse twenty three', 'The steps of a good man are ordered by the Lord: and he delighteth in his way.'],
        'friend' => [['friend', 'friends', 'talk'], 'Proverbs eighteen, verse twenty four', 'There is a friend that sticketh closer than a brother.'],
        'hope' => [['hope', 'promise', 'promises', 'trust'], 'Romans fifteen, verse thirteen', 'Now the God of hope fill you with all joy and peace in believing.'],
        'holy' => [['holy', 'majesty', 'king', 'reign', 'throne', 'crown'], 'Isaiah six, verse three', 'Holy, holy, holy, is the Lord of hosts: the whole earth is full of his glory.'],
        'name' => [['name', 'jesus', 'savior', 'saviour'], 'Philippians two, verse ten', 'At the name of Jesus every knee should bow.'],
        'rest' => [['rest', 'tired', 'weary', 'burden'], 'Matthew eleven, verse twenty eight', 'Come unto me, all ye that labour and are heavy laden, and I will give you rest.'],
        'hand' => [['hand', 'hands', 'hold', 'arms', 'touch', 'touched'], 'Isaiah forty one, verse ten', 'Fear thou not; for I am with thee: be not dismayed; for I am thy God: I will strengthen thee.'],
        'forever' => [['forever', 'eternal', 'everlasting', 'always', 'lives'], 'Hebrews thirteen, verse eight', 'Jesus Christ the same yesterday, and to day, and for ever.'],
        'spirit' => [['spirit', 'fire', 'breath', 'power'], 'Zechariah four, verse six', 'Not by might, nor by power, but by my spirit, saith the Lord of hosts.'],
        'prayer' => [['prayer', 'pray', 'praying', 'knees'], 'Philippians four, verse six', 'In every thing by prayer and supplication with thanksgiving let your requests be made known unto God.'],
    ];

    private const array SONG_FRAMES = [
        'That song we just heard, %s, stays with me.',
        '%s. What a song to hear at this hour.',
        'I love that one. %s.',
        'You know, %s has a way of saying what my heart cannot.',
        'Every time %s plays, I find myself singing along.',
    ];

    private const array SONG_FRAMES_ARTIST = [
        '%s, from %s. What a blessing.',
        'That was %s by %s, and it is still ringing in my ears.',
        'Did you catch the words of %s just now? %s sang that one from the heart.',
        '%s by %s. Some songs just feel like home.',
    ];

    private const array SONG_THOUGHTS = [
        'Music like that was made to lift our eyes from our troubles to the One who is bigger than all of them.',
        'A good song is like a prayer that you can carry with you after the music stops.',
        'If that one touched something in you tonight, just say thank you, Lord. He is listening.',
        'Songs like that remind me that we are never the first to walk through a hard night. Others have walked it with Jesus and sung on the way.',
        'Let those words keep playing in your heart a while.',
        'I hope you sang along. Nobody is watching at this hour except the Lord, and He loves your voice.',
        'That is the kind of song that makes the kitchen at two in the morning feel like a sanctuary.',
    ];

    private const array NIGHT_THOUGHTS = [
        'Most of the world is asleep, but the Lord is wide awake and paying attention to you.',
        'The night hours have always been special to God\'s people. David wrote that he remembered the Lord upon his bed and meditated on Him in the night watches.',
        'If you are awake right now, maybe it is not an accident. Maybe it is an invitation to talk with Him.',
        'The house creaks, the coffee pot hums, and God is still on His throne.',
        'Jesus Himself often went out alone at night to pray. You are in good company.',
        'There is something about the quiet of the night that makes it easier to hear the still small voice.',
        'Somewhere right now, someone is praying for people they will never meet. Maybe even for you.',
        'Paul and Silas sang hymns at midnight in a prison cell. If they could sing then, we can sing now.',
        'The stars are out over all of us, and Isaiah says God calls every one of them by name.',
        'I like to think of the overnight hours as a quiet little chapel that stays open all night.',
    ];

    private const array NIGHT_CLOSERS = [
        'Let\'s keep each other company.',
        'I am glad you are here.',
        'More good music is on the way.',
        'Stay with me a while.',
        'You are not up alone.',
        'Let\'s let the music do some praying for us.',
    ];

    private const array WEEKDAYS = [
        'Monday' => 'It is Monday, and a new week stands in front of us. You do not have to win the whole week tonight. Just walk with Jesus one day at a time.',
        'Tuesday' => 'Tuesday. Nobody ever writes songs about Tuesday, but God is just as present on an ordinary Tuesday as on Easter morning.',
        'Wednesday' => 'Wednesday, middle of the week. In a lot of churches it is prayer meeting night, so let\'s keep that going. Who can you pray for right now?',
        'Thursday' => 'Thursday already. The week is flying by, but God is never rushed and never late.',
        'Friday' => 'It is Friday. Whatever this week held, God was faithful in every single day of it.',
        'Saturday' => 'Saturday. A good day to rest, to catch up, and maybe to get ready to worship with God\'s people tomorrow.',
        'Sunday' => 'It is the Lord\'s day. Whether you made it to church or you are worshiping right here by the radio, He is worthy of our praise.',
    ];

    /**
     * Book => [chapters, one line about it].
     */
    private const array BOOKS = [
        'Genesis' => [50, 'It is the book of beginnings: creation, the fall, and God\'s promise to Abraham.'],
        'Exodus' => [40, 'It tells how God delivered His people out of Egypt and gave the Ten Commandments.'],
        'Leviticus' => [27, 'It teaches about holiness and how a holy God made a way for His people to come near.'],
        'Numbers' => [36, 'It follows Israel through the wilderness, where God fed them every single day.'],
        'Deuteronomy' => [34, 'It is Moses\' farewell, calling God\'s people to love the Lord with all their heart.'],
        'Joshua' => [24, 'It is where God told Joshua to be strong and of a good courage.'],
        'Judges' => [21, 'It shows God raising up deliverers again and again when His people cried out.'],
        'Ruth' => [4, 'It is a love story of loyalty and of a kinsman redeemer.'],
        '1 Samuel' => [31, 'It gives us Hannah\'s prayer, young Samuel, and David and Goliath.'],
        '2 Samuel' => [24, 'It tells of King David\'s reign, his failures, and God\'s mercy.'],
        '1 Kings' => [22, 'It includes Solomon\'s temple and Elijah on Mount Carmel.'],
        '2 Kings' => [25, 'It is full of Elisha\'s miracles and the long story of the kings.'],
        '1 Chronicles' => [29, 'It retells Israel\'s history with a special focus on worship.'],
        '2 Chronicles' => [36, 'It records that wonderful promise: if my people, which are called by my name, shall humble themselves, and pray.'],
        'Ezra' => [10, 'It tells of God\'s people coming home and rebuilding the temple.'],
        'Nehemiah' => [13, 'It shows the walls of Jerusalem rebuilt with prayer and hard work.'],
        'Esther' => [10, 'It shows God working behind the scenes for such a time as this.'],
        'Job' => [42, 'It wrestles with suffering and ends with God\'s faithfulness.'],
        'Psalms' => [150, 'They are the prayer book and the hymnbook of God\'s people.'],
        'Proverbs' => [31, 'It is full of practical wisdom, one chapter for every day of a long month.'],
        'Ecclesiastes' => [12, 'It reminds us that everything has its season under heaven.'],
        'Song Of Solomon' => [8, 'It is a poem celebrating love.'],
        'Isaiah' => [66, 'It is sometimes called the fifth gospel, because it sees Jesus so clearly.'],
        'Jeremiah' => [52, 'It holds the promise that God knows the plans He has for us.'],
        'Lamentations' => [5, 'In the middle of grief it says, great is thy faithfulness.'],
        'Ezekiel' => [48, 'It has the vision of dry bones coming to life.'],
        'Daniel' => [12, 'It gives us the lions\' den and the fiery furnace.'],
        'Hosea' => [14, 'It shows God\'s love pursuing an unfaithful people.'],
        'Joel' => [3, 'It promises God will pour out His Spirit.'],
        'Amos' => [9, 'It calls for justice to roll down like waters.'],
        'Obadiah' => [1, 'It is the shortest book in the Old Testament.'],
        'Jonah' => [4, 'It shows God\'s mercy reaching even Nineveh, and even a runaway prophet.'],
        'Micah' => [7, 'It asks what the Lord requires: to do justly, love mercy, and walk humbly with thy God.'],
        'Nahum' => [3, 'It says the Lord is good, a strong hold in the day of trouble.'],
        'Habakkuk' => [3, 'It ends with a song of joy even when the fig tree does not blossom.'],
        'Zephaniah' => [3, 'It says the Lord will rejoice over us with singing.'],
        'Haggai' => [2, 'It encouraged the people to rebuild God\'s house.'],
        'Zechariah' => [14, 'It says, not by might, nor by power, but by my spirit.'],
        'Malachi' => [4, 'It is the last book of the Old Testament.'],
        'Matthew' => [28, 'It opens the New Testament and gives us the Sermon on the Mount.'],
        'Mark' => [16, 'It is the shortest of the four gospels and always on the move.'],
        'Luke' => [24, 'It gives us the Christmas story and the prodigal son.'],
        'John' => [21, 'It was written so that we might believe that Jesus is the Christ.'],
        'Acts' => [28, 'It tells how the Holy Spirit filled the early church.'],
        'Romans' => [16, 'It tells us nothing can separate us from the love of God.'],
        '1 Corinthians' => [16, 'It has the great love chapter, chapter thirteen.'],
        '2 Corinthians' => [13, 'It says God\'s grace is sufficient for us.'],
        'Galatians' => [6, 'It celebrates freedom in Christ and the fruit of the Spirit.'],
        'Ephesians' => [6, 'It says we are saved by grace through faith.'],
        'Philippians' => [4, 'It is Paul\'s letter of joy, written from a prison.'],
        'Colossians' => [4, 'It lifts up Christ as before all things.'],
        '1 Thessalonians' => [5, 'It tells us to pray without ceasing.'],
        '2 Thessalonians' => [3, 'It encourages believers not to grow weary in well doing.'],
        '1 Timothy' => [6, 'It is Paul\'s letter to a young pastor.'],
        '2 Timothy' => [4, 'It holds Paul\'s words, I have fought a good fight, I have kept the faith.'],
        'Titus' => [3, 'It speaks of the grace of God that bringeth salvation.'],
        'Philemon' => [1, 'It is a short, warm letter about forgiveness.'],
        'Hebrews' => [13, 'It has the great hall of faith in chapter eleven.'],
        'James' => [5, 'It tells us faith shows itself in what we do.'],
        '1 Peter' => [5, 'It tells us to cast all our care upon Him.'],
        '2 Peter' => [3, 'It reminds us the Lord is not slack concerning His promise.'],
        '1 John' => [5, 'It says God is love.'],
        '2 John' => [1, 'It is one of the shortest books in the Bible.'],
        '3 John' => [1, 'It is one of the shortest books in the Bible.'],
        'Jude' => [1, 'It ends with a beautiful blessing about the One who is able to keep you from falling.'],
        'Revelation' => [22, 'It ends with Jesus saying, Surely I come quickly.'],
    ];
}
