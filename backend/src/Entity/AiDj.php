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
}
