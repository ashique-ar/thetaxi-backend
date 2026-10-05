<?php

it('searches users by readable identity, role and context type instead of internal IDs', function () {
    $source = file_get_contents(app_path('Services/UserService.php'));
    $search = Str::between($source, "if (!empty(\$filters['search'])) {", "if (!empty(\$filters['role'])) {");

    expect($search)
        ->toContain("whereLikeInsensitive('first_name', \$search)")
        ->toContain("whereLikeInsensitive('email', \$search)")
        ->toContain("whereLikeInsensitive('context_type', \$search)")
        ->not->toContain("whereLikeInsensitive('id', \$search)", "whereLikeInsensitive('context_id', \$search)");
});
