<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Interfaces\IdentifiableEntityInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Component\Validator\Constraints as Assert;

#[
    ORM\Entity,
    ORM\Table(name: 'ai_dj'),
    Attributes\Auditable
]
final class AiDj implements Stringable, IdentifiableEntityInterface
{
    // Breaks a music bed can play under. The content ones are AiDjContent types.
    public const string BED_SHIFT_INTRO = 'shift_intro';
    public const string BED_SHIFT_OUTRO = 'shift_outro';
    public const string BED_SONG_TALK = 'song_talk';
    public const string BED_SHORT_LINER = 'short_liner';

    public const array BED_BREAKS = [
        self::BED_SHIFT_INTRO,
        self::BED_SHIFT_OUTRO,
        AiDjContent::TYPE_BIBLE_VERSE,
        AiDjContent::TYPE_ENCOURAGEMENT,
        AiDjContent::TYPE_INSPIRATION,
        AiDjContent::TYPE_TESTIMONY,
        AiDjContent::TYPE_STORY,
        AiDjContent::TYPE_JOKE,
        self::BED_SONG_TALK,
        self::BED_SHORT_LINER,
    ];

    /** The start and end of the shift, and the breaks where the DJ talks for a while. */
    public const array BED_DEFAULT_BREAKS = [
        self::BED_SHIFT_INTRO,
        self::BED_SHIFT_OUTRO,
        AiDjContent::TYPE_BIBLE_VERSE,
        AiDjContent::TYPE_ENCOURAGEMENT,
        AiDjContent::TYPE_INSPIRATION,
        AiDjContent::TYPE_TESTIMONY,
        AiDjContent::TYPE_STORY,
    ];

    use Traits\HasAutoIncrementId;
    use Traits\TruncateStrings;

    #[
        ORM\ManyToOne(targetEntity: Station::class),
        ORM\JoinColumn(name: 'station_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')
    ]
    private Station $station;

    #[
        ORM\Column(nullable: false, insertable: false, updatable: false)
    ]
    private int $station_id;

    #[
        ORM\Column(length: 255, nullable: false),
        Assert\NotBlank
    ]
    private string $name = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $is_enabled = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $voice_model_path = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $shift_intro_template = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $shift_outro_template = null;

    /** Talk frequency: 0.0 (never) to 1.0 (every song). Default 0.5 = ~50% of songs. */
    #[ORM\Column(type: 'float', options: ['default' => 0.5])]
    private float $talk_frequency = 0.5;

    /** Voice speed: 0.7 (slow) to 1.5 (fast). Default 1.0 = normal speed. */
    #[ORM\Column(type: 'float', options: ['default' => 1.0])]
    private float $voice_speed = 1.0;

    /** When true, a soft ambient music bed is mixed under the DJ's voice clips. */
    #[ORM\Column(options: ['default' => false])]
    private bool $use_background_audio = false;

    /** The music bed uploaded for this DJ (absolute path), or null for none. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $background_audio_path = null;

    /** The DJ's own recorded breaks played in place of an AI break, per clock hour; 0 is off. */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $recordings_per_hour = 0;

    /**
     * Which breaks get the music bed (BED_* keys); null means the defaults.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $background_audio_breaks = null;

    /** @var Collection<int, AiDjSchedule> */
    #[
        ORM\OneToMany(
            targetEntity: AiDjSchedule::class,
            mappedBy: 'ai_dj',
            cascade: ['persist', 'remove'],
            fetch: 'EXTRA_LAZY'
        )
    ]
    private Collection $schedules;

    /** @var Collection<int, AiDjContent> */
    #[
        ORM\ManyToMany(targetEntity: AiDjContent::class, inversedBy: 'ai_djs', fetch: 'EXTRA_LAZY'),
        ORM\JoinTable(
            name: 'ai_dj_has_content',
            joinColumns: [new ORM\JoinColumn(name: 'ai_dj_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
            inverseJoinColumns: [new ORM\JoinColumn(name: 'ai_dj_content_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
        )
    ]
    private Collection $contents;

    public function __construct()
    {
        $this->schedules = new ArrayCollection();
        $this->contents = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getStation(): Station
    {
        return $this->station;
    }

    public function setStation(Station $station): void
    {
        $this->station = $station;
    }

    public function getStationId(): int
    {
        return $this->station_id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $this->truncateString($name);
    }

    public function isEnabled(): bool
    {
        return $this->is_enabled;
    }

    public function setIsEnabled(bool $isEnabled): void
    {
        $this->is_enabled = $isEnabled;
    }

    public function getVoiceModelPath(): ?string
    {
        return $this->voice_model_path;
    }

    public function setVoiceModelPath(?string $voiceModelPath): void
    {
        $this->voice_model_path = $this->truncateNullableString($voiceModelPath);
    }

    public function getShiftIntroTemplate(): ?string
    {
        return $this->shift_intro_template;
    }

    public function setShiftIntroTemplate(?string $shiftIntroTemplate): void
    {
        $this->shift_intro_template = $shiftIntroTemplate;
    }

    public function getShiftOutroTemplate(): ?string
    {
        return $this->shift_outro_template;
    }

    public function setShiftOutroTemplate(?string $shiftOutroTemplate): void
    {
        $this->shift_outro_template = $shiftOutroTemplate;
    }

    public function getTalkFrequency(): float
    {
        return $this->talk_frequency;
    }

    public function setTalkFrequency(float $talkFrequency): void
    {
        $this->talk_frequency = max(0.0, min(1.0, $talkFrequency));
    }

    public function getVoiceSpeed(): float
    {
        return $this->voice_speed;
    }

    public function setVoiceSpeed(float $voiceSpeed): void
    {
        $this->voice_speed = max(0.7, min(1.5, $voiceSpeed));
    }

    public function useBackgroundAudio(): bool
    {
        return $this->use_background_audio;
    }

    public function setUseBackgroundAudio(bool $useBackgroundAudio): void
    {
        $this->use_background_audio = $useBackgroundAudio;
    }

    public function getRecordingsPerHour(): int
    {
        return $this->recordings_per_hour;
    }

    public function setRecordingsPerHour(int $perHour): void
    {
        $this->recordings_per_hour = max(0, min(4, $perHour));
    }

    public function getBackgroundAudioPath(): ?string
    {
        return $this->background_audio_path;
    }

    public function setBackgroundAudioPath(?string $path): void
    {
        $this->background_audio_path = $path;
    }

    /** @return list<string> */
    public function getBackgroundAudioBreaks(): array
    {
        return $this->background_audio_breaks ?? self::BED_DEFAULT_BREAKS;
    }

    /** @param list<string>|null $breaks */
    public function setBackgroundAudioBreaks(?array $breaks): void
    {
        $this->background_audio_breaks = null === $breaks
            ? null
            : array_values(array_intersect(self::BED_BREAKS, $breaks));
    }

    /** @return Collection<int, AiDjSchedule> */
    public function getSchedules(): Collection
    {
        return $this->schedules;
    }

    public function addSchedule(AiDjSchedule $schedule): void
    {
        if (!$this->schedules->contains($schedule)) {
            $this->schedules->add($schedule);
            $schedule->setAiDj($this);
        }
    }

    public function removeSchedule(AiDjSchedule $schedule): void
    {
        $this->schedules->removeElement($schedule);
    }

    /** @return Collection<int, AiDjContent> */
    public function getContents(): Collection
    {
        return $this->contents;
    }

    public function addContent(AiDjContent $content): void
    {
        if (!$this->contents->contains($content)) {
            $this->contents->add($content);
        }
    }

    public function removeContent(AiDjContent $content): void
    {
        $this->contents->removeElement($content);
    }

    public function __clone(): void
    {
        $this->schedules = new ArrayCollection();
        $this->contents = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function api(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_enabled' => $this->is_enabled,
            'voice_model_path' => $this->voice_model_path,
            'shift_intro_template' => $this->shift_intro_template,
            'shift_outro_template' => $this->shift_outro_template,
            'talk_frequency' => $this->talk_frequency,
            'voice_speed' => $this->voice_speed,
            'use_background_audio' => $this->use_background_audio,
            'background_audio_file' => null !== $this->background_audio_path
                ? basename($this->background_audio_path)
                : null,
            'background_audio_breaks' => $this->getBackgroundAudioBreaks(),
            'recordings_per_hour' => $this->recordings_per_hour,
            'schedules' => array_map(
                static fn(AiDjSchedule $schedule): array => $schedule->api(),
                $this->schedules->toArray()
            ),
        ];
    }
}
