<?php

declare(strict_types=1);

namespace App\Controller\Api\Stations\AiDjContent;

use App\Controller\SingleActionInterface;
use App\Entity\AiDjContent;
use App\Entity\Repository\AiDjContentRepository;
use App\Http\Response;
use App\Http\ServerRequest;
use App\OpenApi;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\Get(
    path: '/station/{station_id}/ai-dj-content/types',
    operationId: 'getStationAiDjContentTypes',
    summary: 'List available content types (built-in + custom) for a station.',
    tags: [OpenApi::TAG_STATIONS_BROADCASTING],
    parameters: [
        new OA\Parameter(ref: OpenApi::REF_STATION_ID_REQUIRED),
    ],
    responses: [
        new OpenApi\Response\Success(),
        new OpenApi\Response\AccessDenied(),
    ]
)]
final readonly class TypesAction implements SingleActionInterface
{
    private const array BUILT_IN_LABELS = [
        'song_intro_template' => 'Song Intros',
        'post_song_template' => 'Post-Song',
        'bible_verse' => 'Bible Verses',
        'joke' => 'Jokes',
        'encouragement' => 'Encouragements',
        'inspiration' => 'Inspiration',
        'testimony' => 'Testimonies',
        'story' => 'Stories',
    ];

    public function __construct(
        private AiDjContentRepository $contentRepo,
    ) {
    }

    public function __invoke(
        ServerRequest $request,
        Response $response,
        array $params
    ): ResponseInterface {
        $station = $request->getStation();
        $allContent = $this->contentRepo->findByStation((int) $station->id);

        // Collect all distinct types from the database
        $dbTypes = [];
        foreach ($allContent as $item) {
            $dbTypes[$item->type] = true;
        }

        // Merge built-in types (always shown) with custom types from DB
        $types = [];
        foreach (self::BUILT_IN_LABELS as $slug => $label) {
            $types[] = [
                'type' => $slug,
                'label' => $label,
                'is_builtin' => true,
            ];
        }

        // Add any custom types not in the built-in list
        foreach (array_keys($dbTypes) as $dbType) {
            if (!isset(self::BUILT_IN_LABELS[$dbType])) {
                $types[] = [
                    'type' => $dbType,
                    'label' => self::slugToLabel($dbType),
                    'is_builtin' => false,
                ];
            }
        }

        return $response->withJson($types);
    }

    /**
     * Convert a slug like "prayer_request" to "Prayer Requests".
     */
    private static function slugToLabel(string $slug): string
    {
        return ucwords(str_replace('_', ' ', $slug));
    }
}
