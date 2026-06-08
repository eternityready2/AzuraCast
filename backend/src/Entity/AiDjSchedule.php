<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Interfaces\IdentifiableEntityInterface;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Component\Validator\Constraints as Assert;

#[
    ORM\Entity,
    ORM\Table(name: 'ai_dj_schedules'),
    Attributes\Auditable
]
final class AiDjSchedule implements Stringable, IdentifiableEntityInterface
{
    use Traits\HasAutoIncrementId;
    use Traits\TruncateStrings;

    #[
        ORM\ManyToOne(targetEntity: AiDj::class, inversedBy: 'schedules'),
        ORM\JoinColumn(name: 'ai_dj_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')
    ]
    private AiDj $ai_dj;

    #[
        ORM\Column(nullable: false, insertable: false, updatable: false)
    ]
    private int $ai_dj_id;

    #[
        ORM\Column(length: 255, nullable: false),
        Assert\NotBlank
    ]
    private string $name = '';

    #[
        ORM\Column(type: 'time_immutable', nullable: false),
        Assert\LessThan(propertyPath: 'endTime')
    ]
    private DateTimeImmutable $start_time;

    #[ORM\Column(type: 'time_immutable', nullable: false)]
    private DateTimeImmutable $end_time;

    /** @var int[] */
    #[
        ORM\Column(type: 'json', nullable: false),
        Assert\All([
            new Assert\Type('integer'),
            new Assert\Range(min: 1, max: 7),
        ])
    ]
    private array $loop_days = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $is_enabled = true;

    public function __construct(AiDj $aiDj)
    {
        $this->ai_dj = $aiDj;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getAiDj(): AiDj
    {
        return $this->ai_dj;
    }

    public function setAiDj(AiDj $aiDj): void
    {
        $this->ai_dj = $aiDj;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $this->truncateString($name);
    }

    public function getStartTime(): DateTimeImmutable
    {
        return $this->start_time;
    }

    public function setStartTime(DateTimeImmutable $startTime): void
    {
        $this->start_time = $startTime;
    }

    public function getEndTime(): DateTimeImmutable
    {
        return $this->end_time;
    }

    public function setEndTime(DateTimeImmutable $endTime): void
    {
        $this->end_time = $endTime;
    }

    /** @return int[] */
    public function getLoopDays(): array
    {
        return $this->loop_days;
    }

    /** @param int[] $loopDays */
    public function setLoopDays(array $loopDays): void
    {
        $this->loop_days = array_values(array_unique($loopDays));
    }

    public function isEnabled(): bool
    {
        return $this->is_enabled;
    }

    public function setIsEnabled(bool $isEnabled): void
    {
        $this->is_enabled = $isEnabled;
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
            'start_time' => $this->start_time->format('H:i'),
            'end_time' => $this->end_time->format('H:i'),
            'loop_days' => $this->loop_days,
            'is_enabled' => $this->is_enabled,
        ];
    }
}
