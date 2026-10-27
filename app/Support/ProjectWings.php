<?php

namespace App\Support;

use App\Models\Wing;

class ProjectWings
{
    /**
     * The wing a project's content is filed into, or null when the caller
     * cannot file there.
     *
     * Before this, the model picked the wing from the transcript alone and was
     * wrong most of the time: in the busiest wings most digested drawers came
     * from other projects. A project with no wing yet gets one -- proposing a
     * new wing only queued it for a review nobody did, so the model squeezed
     * unknown projects into whatever wing looked closest. A wing-restricted
     * token never creates wings and never reaches outside its wings.
     *
     * @param  array<int, Wing>  $tokenWings
     * @param  array<string>|null  $patterns
     */
    public static function wingSlugForProject(string $project, array $tokenWings, ?array $patterns): ?string
    {
        $wing = Wing::forProject($project);

        if ($wing !== null) {
            $visible = collect($tokenWings)->contains(fn (Wing $w) => $w->id === $wing->id);

            return $visible ? $wing->slug : null;
        }

        $name = Wing::slugify($project);

        return ($patterns === null && $name !== '') ? "project-{$name}" : null;
    }
}
