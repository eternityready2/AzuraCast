<?php

declare(strict_types=1);

namespace App\Entity\Fixture;

use App\Entity\AiDj;
use App\Entity\AiDjContent;
use App\Entity\AiDjSchedule;
use App\Entity\Station;
use DateTimeImmutable;
use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Seeds one default AI DJ personality plus starter content library rows
 * so a fresh install is not a blank AI DJ page.
 *
 * Content seeded from production sample exports (sample_*.txt).
 * Song intros keep a small starter set; production may already have more.
 */
final class AiDjFixture extends AbstractFixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $station = $this->getReference('station', Station::class);

        $dj = new AiDj();
        $dj->setStation($station);
        $dj->setName('Morning Host');
        $dj->setIsEnabled(true);
        $dj->setVoiceModelPath('kokoro:am_adam');
        $dj->setTalkFrequency(0.5);
        $dj->setVoiceSpeed(1.0);
        $dj->setUseBackgroundAudio(false);
        $dj->setShiftIntroTemplate(
            'Hey, this is {{dj_name}} on {{station_name}}. Welcome to the show — glad you\'re here!'
        );
        $dj->setShiftOutroTemplate(
            'This has been {{dj_name}} on {{station_name}}. Thanks for listening — stay blessed!'
        );

        $schedule = new AiDjSchedule($dj);
        $schedule->setName('Weekday mornings');
        $schedule->setStartTime(new DateTimeImmutable('06:00:00'));
        $schedule->setEndTime(new DateTimeImmutable('10:00:00'));
        $schedule->setLoopDays([1, 2, 3, 4, 5]);
        $schedule->setIsEnabled(true);
        $dj->addSchedule($schedule);

        // Starter content library, based on production sample exports.

        /** @var list<string> $songIntros */
        $songIntros = [
            'Coming up next on {{station_name}}: {{artist}} with {{song}}. You\'re going to love this one.',
            'This is {{dj_name}}, and next up we have {{artist}} performing {{song}}. Sit back and enjoy.',
            'You\'re tuned into {{station_name}}. Get ready for {{song}} by {{artist}} — here it is.',
            'Right now on {{station_name}}, it\'s {{artist}} with {{song}}. Turn it up!',
            'I\'m {{dj_name}}, and I\'ve got something special: {{artist}} bringing you {{song}}.',
        ];

        /** @var list<string> $postSong */
        $postSong = [
            'That was {{prev_artist}} with {{prev_song}}. You\'re listening to {{station_name}} with {{dj_name}}. What a beautiful song, I hope it touched your heart today. We\'ve got more great music coming your way.',
            'Hope you enjoyed {{prev_song}} by {{prev_artist}}. I\'m {{dj_name}} and this is {{station_name}}. If that song blessed you today, let me tell you, there is so much more where that came from. Stay with us.',
            '{{prev_artist}}, {{prev_song}}. Beautiful. This is {{dj_name}} on {{station_name}}. I love sharing these songs with you. Stay tuned, we have plenty more uplifting music on the way.',
            'That was {{prev_song}} by {{prev_artist}}. Stay with us on {{station_name}}, because we have more amazing music lined up for you. I\'m {{dj_name}} and I\'m so glad you\'re here with us today.',
            'What a song. {{prev_artist}} with {{prev_song}}. This is {{dj_name}} on {{station_name}} and coming up next, {{next_artist}} with {{next_song}}. You are going to love this one, so do not go anywhere.',
            '{{prev_artist}}, {{prev_song}}. Next up on {{station_name}}, we have {{next_artist}} bringing you some more beautiful music. I\'m {{dj_name}} and I\'m blessed to be here with you today.',
            'That was {{prev_song}}. I\'m {{dj_name}} and you\'re listening to {{station_name}}. Coming up next, we have {{next_artist}} with another wonderful song. Keep listening and be blessed.',
            'Beautiful music from {{prev_artist}} right there. This is {{station_name}} with {{dj_name}}. I love being here with you, sharing these songs that lift the spirit. Keep listening, there is so much more.',
        ];

        /** @var list<string> $jokes */
        $jokes = [
            'Why did the church choir have to cancel rehearsal? Because everyone was too cross.',
            'What do you call a sleepwalking nun? A roamin\' Catholic.',
            'Why don\'t Christians use sunscreen? Because they already have Son protection.',
            'What kind of car did the disciples drive? A Honda — because the apostles were all in one Accord.',
            'Why did the Pharisee cross the road? To tithe on the other side.',
            'What do you call a prophet who can\'t speak? A non-prophet.',
            'How does Moses make his coffee? Hebrews it.',
            'Why was the Bible student always calm? Because he had a lot of patients — he was reading about Job.',
            'Why did Noah have trouble sleeping? Because of all the snoring — there were two of everything!',
            'What did Adam say on the day before Christmas? It\'s Christmas, Eve.',
            'Where did Noah keep his bees? In the ark-hives.',
            'What\'s a Christian\'s favorite card game? Go Fish — just like the disciples.',
            'Why couldn\'t Jonah trust the ocean? Because he knew there was something fishy going on.',
            'Who was the fastest runner in the Bible? Adam — he was first in the human race.',
            'Why did the unemployed man get excited reading the Bible? Because he heard it had a lot of Job openings.',
            'What kind of man was Boaz before he got married? Ruth-less.',
            'Why did the bishop bring a ladder to church? Because he heard the sermon was going to be on a higher level.',
            'Why was Goliath so surprised when David hit him? He never had something like that come across his mind before.',
            'Why didn\'t they play cards on Noah\'s ark? Because Noah sat on the deck.',
            'How do angels greet each other? They say, "Halo there!"',
        ];

        /**
         * Bible verses: content = verse text, reference = citation.
         *
         * @var list<array{content: string, reference: string}> $bibleVerses
         */
        $bibleVerses = [
            [
                'content' => 'In the beginning God created the heavens and the earth.',
                'reference' => 'Genesis 1:1',
            ],
            [
                'content' => 'And the earth was waste and void; and darkness was upon the face of the deep: and the Spirit of God moved upon the face of the waters.',
                'reference' => 'Genesis 1:2',
            ],
            [
                'content' => 'And God said, Let there be light: and there was light.',
                'reference' => 'Genesis 1:3',
            ],
            [
                'content' => 'And God saw the light, that it was good: and God divided the light from the darkness.',
                'reference' => 'Genesis 1:4',
            ],
            [
                'content' => 'And God called the light Day, and the darkness he called Night. And there was evening and there was morning, one day.',
                'reference' => 'Genesis 1:5',
            ],
            [
                'content' => 'And God said, Let there be a firmament in the midst of the waters, and let it divide the waters from the waters.',
                'reference' => 'Genesis 1:6',
            ],
            [
                'content' => 'And God made the firmament, and divided the waters which were under the firmament from the waters which were above the firmament: and it was so.',
                'reference' => 'Genesis 1:7',
            ],
            [
                'content' => 'And God called the firmament Heaven. And there was evening and there was morning, a second day.',
                'reference' => 'Genesis 1:8',
            ],
            [
                'content' => 'And God said, Let the waters under the heavens be gathered together unto one place, and let the dry land appear: and it was so.',
                'reference' => 'Genesis 1:9',
            ],
            [
                'content' => 'And God called the dry land Earth; and the gathering together of the waters called he Seas: and God saw that it was good.',
                'reference' => 'Genesis 1:10',
            ],
            [
                'content' => 'And God said, Let the earth put forth grass, herbs yielding seed, and fruit-trees bearing fruit after their kind, wherein is the seed thereof, upon the earth: and it was so.',
                'reference' => 'Genesis 1:11',
            ],
            [
                'content' => 'And the earth brought forth grass, herbs yielding seed after their kind, and trees bearing fruit, wherein is the seed thereof, after their kind: and God saw that it was good.',
                'reference' => 'Genesis 1:12',
            ],
            [
                'content' => 'And there was evening and there was morning, a third day.',
                'reference' => 'Genesis 1:13',
            ],
            [
                'content' => 'And God said, Let there be lights in the firmament of heaven to divide the day from the night; and let them be for signs, and for seasons, and for days and years:',
                'reference' => 'Genesis 1:14',
            ],
            [
                'content' => 'and let them be for lights in the firmament of heaven to give light upon the earth: and it was so.',
                'reference' => 'Genesis 1:15',
            ],
            [
                'content' => 'And God made the two great lights; the greater light to rule the day, and the lesser light to rule the night: he madethe stars also.',
                'reference' => 'Genesis 1:16',
            ],
            [
                'content' => 'And God set them in the firmament of heaven to give light upon the earth,',
                'reference' => 'Genesis 1:17',
            ],
            [
                'content' => 'and to rule over the day and over the night, and to divide the light from the darkness: and God saw that it was good.',
                'reference' => 'Genesis 1:18',
            ],
            [
                'content' => 'And there was evening and there was morning, a fourth day.',
                'reference' => 'Genesis 1:19',
            ],
            [
                'content' => 'And God said, Let the waters swarm with swarms of living creatures, and let birds fly above the earth in the open firmament of heaven.',
                'reference' => 'Genesis 1:20',
            ],
        ];

        /** @var list<string> $encouragements */
        $encouragements = [
            'You are not too far gone. No distance is too great for God\'s love to reach. He crossed all of eternity to find you — a few more miles won\'t stop Him.',
            'Your prayers are heard. Even the ones you whispered in the dark, even the ones that came out as tears instead of words. God hears all of it.',
            'The season you\'re in is not the season you\'ll stay in. God is not finished with you. What feels like an ending is often just the pause before something new begins.',
            'You don\'t have to have it all together for God to use you. He has always worked best through imperfect people. He doesn\'t need your polish; He needs your willingness.',
            'Your faith doesn\'t have to be large. Jesus said faith the size of a mustard seed can move mountains. Small and sincere is more than enough to start.',
            'God sees you on the ordinary days — not just the big moments. He is present in the Tuesday afternoons and the unremarkable mornings. He is in the details of your everyday life.',
            'You are not fighting alone. The God who parted the sea and raised the dead is the same God who walks alongside you today. You have a Companion who has never lost a battle.',
            'Your past does not define your future in God\'s hands. He is the God of new beginnings. Every morning His mercies are new — not recycled, not leftover, but brand new.',
            'When you feel forgotten, remember: God knows the number of hairs on your head. He has written your name on the palms of His hands. You are not forgotten.',
            'Rest is not weakness. Even Jesus pulled away from the crowd to rest and pray. Taking care of yourself is not a failure of faith — it\'s wisdom.',
            'It is okay to cry. Jesus wept. God is not uncomfortable with your grief. He draws near to the brokenhearted and sits with those who mourn.',
            'You are exactly where God can find you. He doesn\'t need you to be in a church, on your knees, or saying the right words. He meets you where you are.',
            'The waiting is not wasted. God is doing something in you during the seasons of waiting that could not happen any other way. Trust the process even when you can\'t see the progress.',
            'Your obedience today is planting seeds for someone else\'s harvest tomorrow. You may never see the fruit, but the planting is never meaningless.',
            'God\'s timing is not your timing — and that is a great mercy. If every prayer had been answered when and how you asked, you would have missed the better answer He was preparing.',
            'You are not a disappointment to God. He knew everything about you before He chose you. He is not surprised by your failures. He chose you anyway.',
            'Hard seasons produce deep roots. Trees that grow in sheltered places have shallow roots. The storms you have survived have made you stronger than you know.',
            'You don\'t have to understand it to trust it. Faith is not about having all the answers. It is about trusting the One who does.',
            'God can turn your mess into a message. What you are most ashamed of may become the very thing He uses to reach someone who needs exactly your story.',
            'You are loved not because of what you do but because of who God is. His love is not a reward for good behavior. It is a permanent fact about His character.',
        ];

        /** @var list<string> $inspiration */
        $inspiration = [
            'You were made for more than survival. God didn\'t save you just to get by — He saved you to thrive, to grow, to shine, and to be a light in the darkness around you.',
            'Every great calling starts with a single "yes." Abraham said yes to leaving home. Mary said yes to the impossible. Your willingness today can change the world tomorrow.',
            'The same God who created the universe knows your name. Every star in every galaxy was placed with precision, and yet He is more interested in you than in all of it combined.',
            'Your faith, exercised today, is building something eternal. What you invest in God\'s kingdom will outlast every earthly thing you will ever touch.',
            'God is looking for people who are willing, not just talented. He can provide the talent. He is looking for the willing heart. Be the one who says "Here I am, Lord."',
            'You were placed in this exact generation on purpose. The problems of your time, your culture, your community — you were designed with those problems in mind. You are part of the solution.',
            'Great things begin in secret. Prayer happens in private. Obedience happens in quiet. Character is forged when no one is watching. Don\'t despise the hidden seasons.',
            'Every door God closes is protecting you from something. And every open door He has prepared is better than any you could have forced open on your own.',
            'The life God is calling you into is bigger than the comfort you are holding onto. Comfort and calling rarely occupy the same space. Choose the calling.',
            'Your testimony is a weapon. The enemy cannot argue with what God has done in your life. Your story, told honestly, is one of the most powerful forces on earth.',
            'God is not in a hurry, and neither should you be. He is building something in you that will stand the test of time. Good things grow at the speed they need to grow.',
            'What you do with what you have is your offering to God. Not what you wish you had — what you actually hold in your hands right now. Give it fully.',
            'One person who is fully surrendered to God can change the atmosphere of a room, a family, a community, a generation.',
            'You are not waiting for the perfect moment to begin. The perfect moment to begin is now. God meets obedience in motion, not in waiting.',
            'The life of faith is the greatest adventure available to human beings. Nothing in this world compares to being used by God to change a life, heal a heart, or bring someone home.',
            'Boldness is not the absence of fear. It is obedience in the presence of fear. Every person God used greatly was afraid. They stepped forward anyway.',
            'You have gifts that the world around you desperately needs. Not one day from now, when you\'re more ready — right now. Your gifts, imperfectly offered, are more powerful than your silence.',
            'God\'s plan for your life will not be derailed by your mistakes. He wrote your story knowing every detour. His plan accounts for your humanity. It always has.',
            'Every prayer you pray in faith is adding up. You may not see the total yet, but the account is growing. Nothing prayed in faith is ever wasted.',
            'You are carrying a light that the darkness around you cannot extinguish. No matter how heavy the dark feels, the light in you is more powerful. Let it shine.',
        ];

        /** @var list<string> $testimonies */
        $testimonies = [
            'Saul of Tarsus, the Apostle Paul - He was dragging Christians to prison and worse, convinced he was serving God. Then a blinding light knocked him to the ground on the road to Damascus, and the voice of the risen Jesus asked why he was persecuting Him. Saul went from the church\'s most feared enemy to its greatest missionary, writing much of the New Testament and dying for the faith he once tried to destroy.',
            'Zacchaeus the Tax Collector - A wealthy, despised cheat who climbed a tree just to see Jesus pass by. Jesus called him down and invited Himself to dinner. Zacchaeus repented on the spot, promising to give half his wealth to the poor and repay everyone he\'d defrauded four times over.',
            'The Woman at the Well - A Samaritan woman living in shame, married five times over and living with a man who wasn\'t her husband. Jesus offered her living water and told her everything she\'d ever done, and instead of condemning her, she ran to tell her whole town about the Messiah she\'d met.',
            'Mary Magdalene - Delivered from seven demons, she became one of Jesus\'s most devoted followers, staying at the cross when most others fled, and was the first person to see the resurrected Christ and proclaim it to the disciples.',
            'Matthew the Tax Collector - A hated collaborator with Rome, sitting at his booth collecting money from his own people. Jesus simply said follow me, and Matthew left everything to become one of the twelve apostles and write a Gospel.',
            'The Thief on the Cross - A convicted criminal dying next to Jesus, with no time left to prove himself or make amends. He simply asked Jesus to remember him, and Jesus promised him paradise that very day.',
            'The Philippian Jailer - After an earthquake broke open his prison, he was about to kill himself, assuming his prisoners had escaped. Paul and Silas stopped him, he asked what he must do to be saved, and that same night he and his whole household were baptized.',
            'Cornelius the Roman Centurion - A God-fearing Gentile soldier who prayed and gave generously. God sent Peter to explain the gospel to him, and Cornelius became one of the first non-Jewish believers, showing the gospel was for every nation.',
            'The Ethiopian Eunuch - A foreign official reading Isaiah in his chariot, confused about its meaning. Philip explained that the passage was about Jesus, and the eunuch believed and was baptized right there in the road.',
            'Lydia of Thyatira - A successful businesswoman whose heart the Lord opened as she listened to Paul preach by a riverside. She was baptized along with her household and became a key supporter of the early church.',
            'Rahab of Jericho - A prostitute in a pagan city who hid Israelite spies because she believed their God was real. Her faith saved her family from destruction, and she is later listed in the lineage of Jesus Himself.',
            'Nicodemus - A respected religious leader who came to Jesus at night, unsure and searching. Jesus told him he must be born again, and Nicodemus eventually became bold enough to help bury Jesus\'s body publicly.',
            'The Gerasene Demoniac - A man so tormented by demons he lived among tombs, cutting himself, unable to be restrained even by chains. After Jesus delivered him, he was found calm, clothed, and begging to follow Jesus, instead sent home to tell others what God had done.',
            'The Woman Caught in Adultery - Dragged before Jesus by accusers ready to stone her. Jesus challenged the sinless to cast the first stone, and when they all left, He told her He didn\'t condemn her either, to go and sin no more.',
            'Simon Peter\'s Restoration - After denying Jesus three times out of fear, Peter was devastated. The risen Jesus met him on a beach and restored him three times over, commissioning him to lead the very church he\'d almost abandoned.',
            'Nathanael - A skeptic who doubted anything good could come from Nazareth. When Jesus told him He\'d seen him under a fig tree before they ever met, Nathanael declared Him the Son of God.',
            'The Woman with the Issue of Blood - Sick and ceremonially unclean for twelve years, having spent everything on doctors with no cure. She touched the hem of Jesus\'s robe in a crowd and was instantly healed, and He called her faith what made her well.',
            'Naaman the Leper - A powerful, proud Syrian general who nearly refused Elisha\'s simple instruction to wash in the Jordan River. When he humbled himself and obeyed, he was healed and declared there was no God in all the earth except Israel\'s.',
            'King Manasseh - One of Judah\'s most wicked kings, guilty of idolatry and even child sacrifice. Taken captive in chains, he humbled himself and repented, and God restored him to his throne, transforming him into a reformer.',
            'Onesimus - A runaway slave who met Paul in prison and became a believer. Paul sent him back to his master Philemon not as property, but as a beloved brother in Christ.',
        ];

        /** @var list<string> $stories */
        $stories = [
            'A man dreamed he walked a beach with God. Behind him stretched two sets of footprints across his life. But in the darkest chapters, only one set remained. He asked why God had left him in those times. God answered softly, "My child, I never left you. In your hardest moments, when you see only one set of footprints, it was then that I was carrying you."',
            'At a massive outdoor gathering, thousands were hungry and there was no food. A small boy tugged on a minister\'s sleeve and offered five rolls and two fish from his bag. The minister prayed over it. What happened next fed everyone present. The boy grew up to be a missionary who always said the same thing: "God doesn\'t need much from you. He just needs what you\'re willing to give."',
            'During the great famine, a poor Irish mother kept a candle burning in her window every night for three years, waiting for her son who had gone overseas. Neighbors thought she was wasting what little she had. But her son came home on a dark night, guided to the cottage by that single flame. She held him and said, "I learned this from God. He keeps a light burning for every child who is lost."',
            'A renowned violinist\'s instrument was cracked moments before a performance. He walked onstage anyway and played. The imperfections in the wood gave the music a richness no one had ever heard from him before. Afterward he said, "I have played on perfect instruments my whole life. Tonight I learned that broken things, in the right hands, can make the most beautiful sound."',
            'A soldier carried a New Testament in his front pocket throughout the war. In a fierce battle, a bullet struck his chest. Medics found the bullet lodged in the pages, stopped cold at Psalm 91: "He shall cover you with His feathers; under His wings you shall take refuge." The soldier wept and surrendered his life to Christ before they reached the hospital.',
            'A social worker visiting an Eastern European orphanage noticed one child who never asked for anything and never smiled. "He stopped expecting anyone to come," the caregiver explained. She sat beside him every day for a week without speaking. On the seventh day he climbed into her lap and whispered, "Are you an angel?" She said, "No. But I know One."',
            'A tired pastor received a call at midnight from a man who said, "I don\'t believe in God, but I had no one else to call." The pastor talked with him until nearly 4 a.m. The man called back the next week, and the week after, and the week after that. Six months later he walked into the church and said, "I came to tell you what God did." The pastor had forgotten the man\'s name — but God hadn\'t.',
            'A grandmother in Alabama prayed every morning over her eleven children and their families for more than fifty years. By the time she went home to God at ninety-two, all eleven children were believers and over forty of her grandchildren were in ministry. At her funeral, her oldest son said, "Mama never preached a word. She just prayed. And somehow, not a single one of us got away."',
            'A pastor felt an unexplained pull to drive across town one cold December night with no reason he could name. He found a man shivering in a doorway with no coat. In the pastor\'s back seat was a coat he had forgotten to drop off at the shelter weeks ago. He draped it over the man\'s shoulders and prayed with him. The man looked up, eyes wet. "I told God that if He was real, someone would bring me a coat tonight."',
            'After her mother passed, a daughter discovered a drawer full of unsent letters — every one addressed to her, written during the years they didn\'t speak. Each letter opened with the same words: "I\'m praying for you today." She read them all in one sitting and wept until she had no more tears. Then she picked up the phone and called her own estranged daughter. The chain of love her mother started was not finished yet.',
            'A boy of twelve stood in church and told the congregation how he had forgiven the classmate who had bullied him for two years. He spoke for four minutes with trembling hands. An elderly deacon told the pastor afterward, "I\'ve sat under men with theology degrees for sixty years. That child just preached the best sermon I\'ve ever heard."',
            'A Texas farmer prayed over his failing land for three years as neighbors sold their properties and moved away. He kept planting. He kept trusting. In the fourth year the rains returned and his harvest was the largest in the county. At a church gathering he said simply, "I wasn\'t trusting in rain. I was trusting in God. The rain was just His answer."',
            'A hospital chaplain noticed a man in the oncology ward who had no visitors in three weeks. He began stopping by daily with two cups of coffee, sitting quietly with no agenda. When the man was discharged, he pressed a folded note into the chaplain\'s hand. It read: "You were the only person who made me feel like I mattered. For the first time, I think God might feel that way too."',
            'At sixteen she ran from home and from God, certain she needed neither. Five years later, in a city far away, she ducked into a church during a rainstorm. The congregation was singing the same hymn her grandmother had sung over her as a child. She sat in the back row and cried for an hour. She called home that night. Her father answered on the first ring. "I\'m ready to come home," she said. He said, "We never stopped waiting."',
            'Workers clearing a bombed European church found a wall painting beneath the debris. A figure of Christ with outstretched arms, the inscription still legible: "Come to Me, all who are weary." The pastor ordered it preserved and left the surrounding walls bare. He said, "Everything else we lost. What we needed most was still standing."',
            'During a long shift caring for critically ill patients, a nurse had nothing left to pray. She simply knelt by her car in the parking lot before going in and said, "Lord, I have no words. Just be with them." She later told her pastor: "I believe that empty, exhausted prayer was answered more completely than any polished prayer I\'ve ever offered. God doesn\'t need our language. He reads our hearts."',
            'A man who had become a pastor of a large congregation tracked down his childhood Sunday school teacher to thank her. She was now elderly and could barely remember those years. He told her: "Everything I know about how to love people, I learned by watching you with us children. You changed my whole life." She wept. "I thought it didn\'t matter," she said. "I thought no one was listening."',
            'A retired plumber sold his home and used every cent to drill water wells in East Africa. When asked why, he simply said, "I read that Jesus told a woman at a well that He had living water. I figured if Jesus cared that much about a well, maybe I should too." He drilled more than thirty wells before he died. In many of those villages, the well opened the door for the gospel.',
            'A choir formed inside a maximum security prison began performing at small churches. At one Sunday service, a woman in the audience recognized a voice — her brother, with whom she had not spoken in twelve years. He had found Christ while incarcerated. She ran to the stage after the last song. The congregation watched two siblings hold each other in the aisle for the first time in over a decade, surrounded by the fading echo of the final hymn.',
            'A missionary in Northern Japan worked seven years without a single convert. He was packing to go home when an elderly woman appeared at his door. "I have been watching you," she said. "How a man lives when no one believes him tells me more than anything he says. I want your God." She became the seed of a church that, within twenty years, had over four hundred members.',
        ];

        $this->persistTexts($manager, $dj, $station, AiDjContent::TYPE_SONG_INTRO_TEMPLATE, $songIntros);
        $this->persistTexts($manager, $dj, $station, AiDjContent::TYPE_POST_SONG_TEMPLATE, $postSong);
        $this->persistTexts($manager, $dj, $station, AiDjContent::TYPE_JOKE, $jokes);
        $this->persistReferenced($manager, $dj, $station, AiDjContent::TYPE_BIBLE_VERSE, $bibleVerses);
        $this->persistTexts($manager, $dj, $station, AiDjContent::TYPE_ENCOURAGEMENT, $encouragements);
        $this->persistTexts($manager, $dj, $station, AiDjContent::TYPE_INSPIRATION, $inspiration);
        $this->persistTexts($manager, $dj, $station, AiDjContent::TYPE_TESTIMONY, $testimonies);
        $this->persistTexts($manager, $dj, $station, AiDjContent::TYPE_STORY, $stories);

        $manager->persist($dj);
        $manager->persist($schedule);
        $manager->flush();

        $this->addReference('ai_dj', $dj);
    }

    /**
     * @param list<string> $texts
     */
    private function persistTexts(
        ObjectManager $manager,
        AiDj $dj,
        Station $station,
        string $type,
        array $texts
    ): void {
        foreach ($texts as $text) {
            $content = $this->makeContent($station, $type, $text);
            $manager->persist($content);
            $dj->addContent($content);
        }
    }

    /**
     * @param list<array{content: string, reference: string}> $items
     */
    private function persistReferenced(
        ObjectManager $manager,
        AiDj $dj,
        Station $station,
        string $type,
        array $items
    ): void {
        foreach ($items as $item) {
            $content = $this->makeContent($station, $type, $item['content'], $item['reference']);
            $manager->persist($content);
            $dj->addContent($content);
        }
    }

    private function makeContent(
        Station $station,
        string $type,
        string $text,
        ?string $reference = null
    ): AiDjContent {
        $content = new AiDjContent($station);
        $content->type = $type;
        $content->content = $text;
        $content->reference = $reference;
        $content->is_enabled = true;
        $content->is_global = false;

        return $content;
    }

    /**
     * @return string[]
     */
    public function getDependencies(): array
    {
        return [
            StationFixture::class,
        ];
    }
}
