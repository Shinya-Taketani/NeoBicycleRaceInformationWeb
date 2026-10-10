<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Presentation\CompositionArchiveView;

use App\Domain\Keirin\Presentation\CompositionResultView\Presenter as SavedPresenter;

final class Presenter
{
    public function __construct(private readonly SavedPresenter $saved) {}

    public function overview(array $archive): array
    {
        $overview = $this->saved->overview(['manifest' => ['request' => ['evaluation_id' => '2025年保存予測アーカイブ',
            'selection' => ['result_year' => 2025]], 'generated_at' => $archive['manifest']['generated_at']],
            'summary' => $archive['summary'], 'races' => array_column($archive['rows'], 'contribution')]);

        return $overview + ['page' => $archive['page'], 'page_count' => $archive['manifest']['page_count']];
    }

    public function detail(array $archive, int $id): ?array
    {
        foreach ($archive['rows'] as $row) {
            if ($row['input']['race_id'] === $id) {
                return $this->saved->detail($this->overview($archive), $row['joined']);
            }
        }

        return null;
    }
}
