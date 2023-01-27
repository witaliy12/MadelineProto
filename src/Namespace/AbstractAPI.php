<?php

declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2023 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Namespace;

use danog\MadelineProto\APIWrapper;
use danog\MadelineProto\Exception;
use InvalidArgumentException;

/**
 * @internal
 */
final class AbstractAPI
{
    private APIWrapper $wrapper;
    public function __construct(private string $namespace)
    {
    }
    public function setWrapper(APIWrapper $wrapper): void
    {
        $this->wrapper = $wrapper;
    }
    /**
     * Call async wrapper function.
     *
     * @param string $name      Method name
     * @param array  $arguments Arguments
     */
    public function __call(string $name, array $arguments)
    {
        if ($arguments && !isset($arguments[0])) {
            $arguments = [$arguments];
        }

        $name = $this->namespace.'.'.$name;
        if (isset(Blacklist::BLACKLIST[$name])) {
            throw new Exception(Blacklist::BLACKLIST[$name]);
        }

        $aargs = isset($arguments[1]) && \is_array($arguments[1]) ? $arguments[1] : [];
        $args = isset($arguments[0]) && \is_array($arguments[0]) ? $arguments[0] : [];
        if (isset($args[0]) && !isset($args['multiple'])) {
            throw new InvalidArgumentException('Parameter names must be provided!');
        }
        return $this->wrapper->getAPI()->methodCallAsyncRead($name, $args, $aargs);
    }
}
