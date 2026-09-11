<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;

/**
 * Accepte une liste en query string sous ses deux formes usuelles :
 * `status=PENDING,APPROVED` comme `status[]=PENDING&status[]=APPROVED`. Les deux
 * arrivent en tableau à la validation (`status.*`).
 */
trait SplitsListQuery
{
    /**
     * @param  list<string>  $keys
     */
    protected function splitListQuery(Request $request, array $keys): void
    {
        foreach ($keys as $key) {
            $value = $request->query($key);
            if (is_string($value)) {
                $request->merge([$key => array_values(array_filter(explode(',', $value)))]);
            }
        }
    }
}
