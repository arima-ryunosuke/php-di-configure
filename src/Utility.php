<?php
# Don't touch this code. This is auto generated.
namespace ryunosuke\castella;

// @formatter:off

/**
 * @codeCoverageIgnore
 */
class Utility
{


    /**
     * クラス定数が存在するか調べる
     *
     * グローバル定数も調べられる。ので実質的には defined とほぼ同じで違いは下記。
     *
     * - defined は単一引数しか与えられないが、この関数は2つの引数も受け入れる
     * - defined は private const で即死するが、この関数はきちんと調べることができる
     * - ClassName::class は常に true を返す
     *
     * あくまで存在を調べるだけで実際にアクセスできるかは分からないので注意（`property_exists` と同じ）。
     *
     * Example:
     * ```php
     * // クラス定数が調べられる（1引数、2引数どちらでも良い）
     * that(const_exists('ArrayObject::STD_PROP_LIST'))->isTrue();
     * that(const_exists('ArrayObject', 'STD_PROP_LIST'))->isTrue();
     * that(const_exists('ArrayObject::UNDEFINED'))->isFalse();
     * that(const_exists('ArrayObject', 'UNDEFINED'))->isFalse();
     * // グローバル（名前空間）もいける
     * that(const_exists('PHP_VERSION'))->isTrue();
     * that(const_exists('UNDEFINED'))->isFalse();
     * ```
     *
     * @package ryunosuke\Functions\Package\classobj
     *
     * @param string|object $classname 調べるクラス
     * @param string $constname 調べるクラス定数
     * @return bool 定数が存在するなら true
     */
    public static function const_exists($classname, $constname = '')
    {
        $colonp = strpos($classname, '::');
        if ($colonp === false && strlen($constname) === 0) {
            return defined($classname);
        }
        if (strlen($constname) === 0) {
            $constname = substr($classname, $colonp + 2);
            $classname = substr($classname, 0, $colonp);
        }

        try {
            $refclass = new \ReflectionClass($classname);
            if (strcasecmp($constname, 'class') === 0) {
                return true;
            }
            return $refclass->hasConstant($constname);
        }
        catch (\Throwable) {
            return false;
        }
    }

    /**
     * オブジェクトのプロパティを可視・不可視を問わず取得する
     *
     * get_object_vars + no public プロパティを返すイメージ。
     * クロージャだけは特別扱いで this + use 変数を返す。
     *
     * Example:
     * ```php
     * $object = new #[\AllowDynamicProperties] class('something', 42) extends \Exception{};
     * $object->oreore = 'oreore';
     *
     * // get_object_vars はそのスコープから見えないプロパティを取得できない
     * // var_dump(get_object_vars($object));
     *
     * // array キャストは全て得られるが null 文字を含むので扱いにくい
     * // var_dump((array) $object);
     *
     * // この関数を使えば不可視プロパティも取得できる
     * that(object_properties($object))->subsetEquals([
     *     'message' => 'something',
     *     'code'    => 42,
     *     'oreore'  => 'oreore',
     * ]);
     *
     * // クロージャは this と use 変数を返す
     * that(object_properties(fn() => $object))->is([
     *     'this'   => $this,
     *     'object' => $object,
     * ]);
     * ```
     *
     * @package ryunosuke\Functions\Package\classobj
     *
     * @param object $object オブジェクト
     * @param array $privates 継承ツリー上の private が格納される
     * @return array 全プロパティの配列
     */
    public static function object_properties($object, &$privates = [])
    {
        if ($object instanceof \Closure) {
            $ref = new \ReflectionFunction($object);
            $uses = method_exists($ref, 'getClosureUsedVariables') ? $ref->getClosureUsedVariables() : $ref->getStaticVariables();
            return ['this' => $ref->getClosureThis()] + $uses;
        }

        $fields = [];
        foreach ((array) $object as $name => $field) {
            $cname = '';
            $names = explode("\0", $name);
            if (count($names) > 1) {
                $name = array_pop($names);
                $cname = $names[1];
            }
            $fields[$cname][$name] = $field;
        }

        $classname = get_class($object);
        $parents = array_values(['', '*', $classname] + class_parents($object));
        uksort($fields, function ($a, $b) use ($parents) {
            return array_search($a, $parents, true) <=> array_search($b, $parents, true);
        });

        $result = [];
        foreach ($fields as $cname => $props) {
            foreach ($props as $name => $field) {
                if ($cname !== '' && $cname !== '*' && $classname !== $cname) {
                    $privates[$cname][$name] = $field;
                }
                if (!array_key_exists($name, $result)) {
                    $result[$name] = $field;
                }
            }
        }

        return $result;
    }

    /**
     * $this を bind 可能なクロージャか調べる
     *
     * Example:
     * ```php
     * that(is_bindable_closure(function () {}))->isTrue();
     * that(is_bindable_closure(static function () {}))->isFalse();
     * ```
     *
     * @package ryunosuke\Functions\Package\funchand
     *
     * @param \Closure $closure 調べるクロージャ
     * @return bool $this を bind 可能なクロージャなら true
     */
    public static function is_bindable_closure(\Closure $closure)
    {
        return !!@$closure->bindTo(new \stdClass());
    }

    /**
     * callable のうち、関数文字列を false で返す
     *
     * 歴史的な経緯で php の callable は多岐に渡る。
     *
     * 1. 単純なコールバック: `"strtolower"`
     * 2. staticメソッドのコール: `["ClassName", "method"]`
     * 3. オブジェクトメソッドのコール: `[$object, "method"]`
     * 4. staticメソッドのコール: `"ClassName::method"`
     * 5. 相対指定によるstaticメソッドのコール: `["ClassName", "parent::method"]`
     * 6. __invoke実装オブジェクト: `$object`
     * 7. クロージャ: `fn() => something()`
     *
     * 上記のうち 1 を callable とはみなさず false を返す。
     * 現代的には `Closure::fromCallable`, `$object->method(...)` などで callable == Closure という概念が浸透しているが、そうでないこともある。
     * 本ライブラリでも `preg_splice` や `array_sprintf` などで頻出しているので関数として定義する。
     *
     * 副作用はなく、クラスのロードや関数の存在チェックなどは行わない。あくまで型と形式で判定する。
     * 引数は callable でなくても構わない。その場合単に false を返す。
     *
     * @package ryunosuke\Functions\Package\funchand
     *
     * @param mixed $callable 対象 callable
     * @return bool 関数呼び出しの callable なら false
     */
    public static function is_callback($callable)
    {
        // 大前提（不要に思えるが invoke や配列 [1, 2, 3] などを考慮すると必要）
        if (!is_callable($callable, true)) {
            return false;
        }

        // 変なオブジェクト・配列は↑で除かれている
        if (is_object($callable) || is_array($callable)) {
            return true;
        }

        // 文字列で :: を含んだら関数呼び出しではない
        if (is_string($callable) && strpos($callable, '::') !== false) {
            return true;
        }

        return false;
    }

    /**
     * php ファイルをパースして名前空間配列を返す
     *
     * ファイル内で use/use const/use function していたり、シンボルを定義していたりする箇所を検出して名前空間単位で返す。
     * クラスコンテキストでの解決できないシンボルはその名前空間として返す。
     * つまり、 use せずに いきなり new Hoge() などとしてもその同一名前空間の Hoge として返す。
     * これは同一名前空間であれば use せずとも使用できる php の仕様に合わせるため。
     * 対象はクラスのみであり、定数・関数は対象外。
     * use せずに hoge_function() などとしても、それが同一名前空間なのかグローバルにフォールバックされるのかは静的には決して分からないため。
     *
     * その他、#[AttributeName]や ClassName::class など、おおよそクラス名が必要とされるコンテキストでのシンボルは全て返される。
     *
     * Example:
     * ```php
     * // このような php ファイルをパースすると・・・
     * file_set_contents(sys_get_temp_dir() . '/namespace.php', '
     * <?php
     * namespace NS1;
     * use ArrayObject as AO;
     * use function strlen as SL;
     * function InnerFunc(){}
     * class InnerClass{}
     * define("OUTER\\\\CONST", "OuterConst");
     *
     * namespace NS2;
     * use RuntimeException as RE;
     * use const COUNT_RECURSIVE as CR;
     * class InnerClass{}
     * const InnerConst = 123;
     *
     * // いきなり Hoge を new してみる
     * new Hoge();
     * ');
     * // このような名前空間配列が得られる
     * that(namespace_parse(sys_get_temp_dir() . '/namespace.php'))->isSame([
     *     'NS1' => [
     *         'const'    => [],
     *         'function' => [
     *             'SL'        => 'strlen',
     *             'InnerFunc' => 'NS1\\InnerFunc',
     *         ],
     *         'alias'    => [
     *             'AO'         => 'ArrayObject',
     *             'InnerClass' => 'NS1\\InnerClass',
     *         ],
     *     ],
     *     'OUTER' => [
     *         'const'    => [
     *             'CONST' => 'OUTER\\CONST',
     *         ],
     *         'function' => [],
     *         'alias'    => [],
     *     ],
     *     'NS2' => [
     *         'const'    => [
     *             'CR'         => 'COUNT_RECURSIVE',
     *             'InnerConst' => 'NS2\\InnerConst',
     *         ],
     *         'function' => [],
     *         'alias'    => [
     *             'RE'         => 'RuntimeException',
     *             'InnerClass' => 'NS2\\InnerClass',
     *             'Hoge'       => 'NS2\\Hoge', // 同一名前空間として返される
     *         ],
     *     ],
     * ]);
     * ```
     *
     * @package ryunosuke\Functions\Package\misc
     *
     * @param string $filename ファイル名
     * @param array $options オプション配列
     * @return array 名前空間配列
     */
    public static function namespace_parse($filename, $options = [])
    {
        $filename = realpath($filename);
        $filemtime = filemtime($filename);
        $options += [
            'cache' => null,
        ];

        $storage = \ryunosuke\castella\Utility::json_storage(__FUNCTION__);

        $storage['mtime'] ??= $filemtime;
        $options['cache'] ??= $storage['mtime'] >= $filemtime;
        if (!$options['cache']) {
            unset($storage['mtime']);
            unset($storage[$filename]);
        }
        return $storage[$filename] ??= (function () use ($filename) {
            $namespace = '';
            $classend = null;

            $tokens = \ryunosuke\castella\Utility::php_tokens(file_get_contents($filename));
            $token = $tokens[0];

            $T_ENUM = defined('T_ENUM') ? T_ENUM : -1; // for compatible
            $result = [];
            while (true) {
                $token = $token->next(["define", T_NAMESPACE, T_USE, T_CONST, T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, $T_ENUM, T_EXTENDS, T_IMPLEMENTS, T_ATTRIBUTE, T_NAME_QUALIFIED, T_STRING]);
                if ($token === null) {
                    break;
                }
                if ($classend !== null && $token->index >= $classend) {
                    $classend = null;
                }

                // define は現在の名前空間とは無関係に名前空間定数を宣言することができる
                if ($token->is(T_STRING) && $token->is("define")) {
                    // ただし実行されないと定義されないので class 内は無視
                    if ($classend !== null) {
                        continue;
                    }

                    // しかも変数が使えたりして静的には決まらないので "" or '' のみとする
                    $token = $token->next([T_CONSTANT_ENCAPSED_STRING, ',']);
                    if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                        $define = trim(stripslashes(substr($token, 1, -1)), '\\');
                        [$ns, $nm] = \ryunosuke\castella\Utility::namespace_split($define);
                        $result[$ns] ??= [
                            'const'    => [],
                            'function' => [],
                            'alias'    => [],
                        ];
                        $result[$ns]['const'][$nm] = $define;
                    }
                }
                // 識別子。多岐に渡るので文脈を見て無視しなければならない
                if ($token->is(T_STRING)) {
                    if ($token->prev()->is([
                        T_OBJECT_OPERATOR,          // $object->member
                        T_NULLSAFE_OBJECT_OPERATOR, // $object?->member
                        T_CONST,                    // const CONST = 'dummy'
                        T_GOTO,                     // goto LABEL
                    ])) {
                        continue;
                    }
                    // hoge_function(named: $argument)
                    if ($token->next()->is(':')) {
                        continue;
                    }
                    // hoge_function()
                    if (!$token->prev()->is(T_NEW) && $token->next()->is('(')) {
                        continue;
                    }
                    if ($token->is([
                        // typehint
                        ...['never', 'void', 'null', 'false', 'true', 'bool', 'int', 'float', 'string', 'object', 'iterable', 'mixed'],
                        // specials
                        ...['self', 'static', 'parent'],
                    ])) {
                        continue;
                    }
                    if (defined($token->text)) {
                        continue;
                    }

                    if (false
                        || $token->prev()->is(T_NEW)           // new ClassName
                        || $token->prev()->is(':')             // function method(): ClassName
                        || $token->next()->is(T_VARIABLE)      // ClassName $argument
                        || $token->next()->is(T_DOUBLE_COLON)  // ClassName::CONSTANT
                    ) {
                        $result[$namespace]['alias'][$token->text] ??= \ryunosuke\castella\Utility::concat($namespace, '\\') . $token->text;
                    }
                }
                // T_STRING とほぼ同じ（修飾版）。T_NAME_QUALIFIED である時点で Space\Name であることはほぼ確定だがいくつか除外するものがある
                if ($token->is(T_NAME_QUALIFIED)) {
                    // hoge_function()
                    if (!$token->prev()->is(T_NEW) && $token->next()->is('(')) {
                        continue;
                    }
                    // 最近の php は標準でも名前空間を持つものがあるので除外しておく
                    if (defined($token->text)) {
                        continue;
                    }
                    $result[$namespace]['alias'][$token->text] ??= \ryunosuke\castella\Utility::concat($namespace, '\\') . $token->text;
                }
                if ($token->is(T_NAMESPACE)) {
                    $token = $token->next();
                    $namespace = $token->text;
                    $result[$namespace] = [
                        'const'    => [],
                        'function' => [],
                        'alias'    => [],
                    ];
                }
                if ($token->is(T_USE)) {
                    // function () **use** ($var) {...}
                    if ($token->prev()?->is(')')) {
                        continue;
                    }
                    // class {**use** Trait;}
                    if ($classend !== null) {
                        while (!$token->is(['{', ';'])) {
                            $token = $token->next(['{', ';', ',']);
                            if (!$token->prev()->is(T_NAME_FULLY_QUALIFIED)) {
                                $result[$namespace]['alias'][$token->prev()->text] ??= \ryunosuke\castella\Utility::concat($namespace, '\\') . $token->prev()->text;
                            }
                        }
                        continue;
                    }

                    $next = $token->next();
                    $key = 'alias';
                    if ($next->is(T_CONST)) {
                        $key = 'const';
                        $token = $next;
                    }
                    if ($next->is(T_FUNCTION)) {
                        $key = 'function';
                        $token = $next;
                    }

                    $token = $token->next();
                    $qualified = trim($token->text, '\\');

                    $next = $token->next();
                    if ($next->is(T_NS_SEPARATOR)) {
                        while (!$token->is('}')) {
                            $token = $token->next(['}', ',', T_AS]);
                            if ($token->is(T_AS)) {
                                $qualified2 = $qualified . "\\" . $token->prev()->text;
                                $result[$namespace][$key][$token->next()->text] = $qualified2;
                                $token = $token->next()->next();
                            }
                            else {
                                $qualified2 = $qualified . "\\" . $token->prev()->text;
                                $result[$namespace][$key][\ryunosuke\castella\Utility::namespace_split($qualified2)[1]] = $qualified2;
                            }
                        }
                    }
                    elseif ($next->is(T_AS)) {
                        $token = $next->next();
                        $result[$namespace][$key][$token->text] = $qualified;
                    }
                    else {
                        $result[$namespace][$key][\ryunosuke\castella\Utility::namespace_split($qualified)[1]] = $qualified;
                    }
                }
                if ($token->is([T_CLASS, T_TRAIT, T_INTERFACE, $T_ENUM])) {
                    // class ClassName {...}, $anonymous = new class() {...}
                    if ($token->next()->is(T_STRING) || $token->prev()->is(T_NEW) || $token->prev(T_ATTRIBUTE)?->prev()->is(T_NEW)) {
                        // new class {}, new class(new class {}) {}
                        $next = $token->next(['{', '(']);
                        if ($next->is('(')) {
                            $next = $next->end()->next('{');
                        }
                        $classend = max($classend ?? -1, $next->end()->index);
                    }
                    // class ClassName
                    if ($token->next()->is(T_STRING)) {
                        $result[$namespace]['alias'][$token->next()->text] = \ryunosuke\castella\Utility::concat($namespace, '\\') . $token->next()->text;
                    }
                }
                if ($token->is(T_EXTENDS)) {
                    while (!$token->is([T_IMPLEMENTS, '{'])) {
                        $token = $token->next([T_IMPLEMENTS, '{', ',']);
                        if (!$token->prev()->is(T_NAME_FULLY_QUALIFIED)) {
                            $result[$namespace]['alias'][$token->prev()->text] ??= \ryunosuke\castella\Utility::concat($namespace, '\\') . $token->prev()->text;
                        }
                    }
                }
                if ($token->is(T_IMPLEMENTS)) {
                    while (!$token->is(['{'])) {
                        $token = $token->next(['{', ',']);
                        if (!$token->prev()->is(T_NAME_FULLY_QUALIFIED)) {
                            $result[$namespace]['alias'][$token->prev()->text] ??= \ryunosuke\castella\Utility::concat($namespace, '\\') . $token->prev()->text;
                        }
                    }
                }
                if ($token->is(T_CONST)) {
                    // class {**const** HOGE=1;}
                    if ($classend !== null) {
                        continue;
                    }
                    $result[$namespace]['const'][$token->next()->text] ??= \ryunosuke\castella\Utility::concat($namespace, '\\') . $token->next()->text;
                }
                if ($token->is(T_FUNCTION)) {
                    // class {**function** hoge() {}}
                    if ($classend !== null) {
                        continue;
                    }
                    // $closure = function () {};
                    if ($token->next()->is('(')) {
                        continue;
                    }
                    $result[$namespace]['function'][$token->next()->text] ??= \ryunosuke\castella\Utility::concat($namespace, '\\') . $token->next()->text;
                }
                if ($token->is(T_ATTRIBUTE)) {
                    $token = $token->next([T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_STRING]);
                    if (!$token->is(T_NAME_FULLY_QUALIFIED)) {
                        $result[$namespace]['alias'][$token->text] ??= \ryunosuke\castella\Utility::concat($namespace, '\\') . $token->text;
                    }
                }
            }

            return $result;
        })();
    }

    /**
     * エイリアス名を完全修飾名に解決する
     *
     * 例えばあるファイルのある名前空間で `use Hoge\Fuga\Piyo;` してるときの `Piyo` を `Hoge\Fuga\Piyo` に解決する。
     *
     * Example:
     * ```php
     * // このような php ファイルがあるとして・・・
     * file_set_contents(sys_get_temp_dir() . '/symbol.php', '
     * <?php
     * namespace vendor\NS;
     *
     * use ArrayObject as AO;
     * use function strlen as SL;
     *
     * function InnerFunc(){}
     * class InnerClass{}
     * ');
     * // 下記のように解決される
     * that(namespace_resolve('AO', sys_get_temp_dir() . '/symbol.php'))->isSame('ArrayObject');
     * that(namespace_resolve('SL', sys_get_temp_dir() . '/symbol.php'))->isSame('strlen');
     * that(namespace_resolve('InnerFunc', sys_get_temp_dir() . '/symbol.php'))->isSame('vendor\\NS\\InnerFunc');
     * that(namespace_resolve('InnerClass', sys_get_temp_dir() . '/symbol.php'))->isSame('vendor\\NS\\InnerClass');
     * ```
     *
     * @package ryunosuke\Functions\Package\misc
     *
     * @param string $shortname エイリアス名
     * @param string|array $nsfiles ファイル名 or [ファイル名 => 名前空間名]
     * @param array $targets エイリアスタイプ（'const', 'function', 'alias' のいずれか）
     * @return string|null 完全修飾名。解決できなかった場合は null
     */
    public static function namespace_resolve(string $shortname, $nsfiles, $targets = ['const', 'function', 'alias'])
    {
        // 既に完全修飾されている場合は何もしない
        if (($shortname[0] ?? null) === '\\') {
            return $shortname;
        }

        // use Inner\Space のような名前空間の use の場合を考慮する
        $parts = explode('\\', $shortname, 2);
        $prefix = isset($parts[1]) ? array_shift($parts) : null;

        if (is_string($nsfiles)) {
            $nsfiles = [$nsfiles => []];
        }

        $targets = (array) $targets;
        foreach ($nsfiles as $filename => $namespaces) {
            $namespaces = array_flip(array_map(fn($n) => trim($n, '\\'), (array) $namespaces));
            foreach (\ryunosuke\castella\Utility::namespace_parse($filename) as $namespace => $ns) {
                /** @noinspection PhpIllegalArrayKeyTypeInspection */
                if (!$namespaces || isset($namespaces[$namespace])) {
                    if (isset($ns['alias'][$prefix])) {
                        return $ns['alias'][$prefix] . '\\' . implode('\\', $parts);
                    }
                    foreach ($targets as $target) {
                        if (isset($ns[$target][$shortname])) {
                            return $ns[$target][$shortname];
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * PhpToken に便利メソッドを生やした配列を返す
     *
     * php_parse とは似て非なる（あっちは何がしたいのかよく分からなくなっている）。
     * この関数はシンプルに PhpToken の拡張版として動作する。
     *
     * 生えているメソッドは下記。
     * - __debugInfo: デバッグしやすい情報で吐き出す
     * - clone: 新プロパティを指定して clone する
     * - name: getTokenName のエイリアス
     * - prev: 条件一致した直前のトークンを返す
     *   - 引数未指定時は isIgnorable でないもの
     * - next: 条件一致した直後のトークンを返す
     *   - 引数未指定時は isIgnorable でないもの
     * - find: ブロック内部を読み飛ばしつつ指定トークンを探す
     * - end: 自身の対応するペアトークンまで飛ばして返す
     *   - 要するに { や (, " などの中途半端ではない終わりのトークンを返す
     * - contents: 自身と end 間のトークンを文字列化する
     * - resolve: text が名前空間を解決して完全修飾になったトークンを返す
     *
     * Example:
     * ```php
     * $phpcode = '<?php
     * // dummy
     * namespace Hogera;
     * class Example
     * {
     *     // something
     * }';
     *
     * $tokens = php_tokens($phpcode);
     * // name でトークン名が得られる
     * that($tokens[0])->name()->is('T_OPEN_TAG');
     * // ↑の次はコメントだが next で namespace が得られる
     * that($tokens[0])->next()->text->is('namespace');
     * // 同じく↑の次はホワイトスペースだが next で Hogera が得られる
     * that($tokens[0])->next()->next()->text->is('Hogera');
     * ```
     *
     * @package ryunosuke\Functions\Package\misc
     *
     * @noinspection PhpPossiblePolymorphicInvocationInspection
     *
     * @param string $phpcode パースする php コード
     * @param int $flags パースオプション
     * @return \PhpTokens[] トークン配列
     */
    public static function php_tokens(string $code, int $flags = 0)
    {
        $PhpToken = null;
        $PhpToken ??= new #[\AllowDynamicProperties] class (0, "") extends \PhpToken {
            public array $tokens;
            public int   $index;

            public function __debugInfo(): array
            {
                $result = get_object_vars($this);

                unset($result['tokens'], $result['cache']);

                $result['name'] = $this->name();
                $result['prev'] = $this->prev()?->getTokenName();
                $result['next'] = $this->next()?->getTokenName();

                return $result;
            }

            public function clone(...$newparams): self
            {
                $that = clone $this;
                foreach ($newparams as $param => $value) {
                    $that->{$param} = $value;
                }
                return $that;
            }

            public function name(): string
            {
                return $this->getTokenName();
            }

            public function prev($condition = null): ?self
            {
                $condition ??= fn($token) => !$token->isIgnorable();
                return $this->sibling(-1, $condition);
            }

            public function next($condition = null): ?self
            {
                $condition ??= fn($token) => !$token->isIgnorable();
                return $this->sibling(+1, $condition);
            }

            public function find($condition): ?self
            {
                $condition = (array) $condition;
                $token = $this;
                while (true) {
                    $token = $token->sibling(+1, array_merge($condition, ['{', '${', '"', T_START_HEREDOC, '#[', '[', '(']));
                    if ($token === null) {
                        return null;
                    }
                    if ($token->is($condition)) {
                        return $token;
                    }
                    $token = $token->end();
                }
            }

            public function end(): self
            {
                $skip = function ($starts, $ends) {
                    $token = $this;
                    while (true) {
                        $token = $token->sibling(+1, array_merge($starts, $ends)) ?? throw new \DomainException(sprintf("token mismatch(line:%d, pos:%d, '%s')", $token->line, $token->pos, $token->text));
                        if ($token->is($starts)) {
                            $token = $token->end();
                        }
                        elseif ($token->is($ends)) {
                            return $token;
                        }
                    }
                };

                if ($this->is('"')) {
                    return $skip(['{', '${'], ['"']);
                }
                if ($this->is('`')) {
                    return $skip(['{', '${'], ['`']);
                }
                if ($this->is(T_START_HEREDOC)) {
                    return $skip(['{', '${'], [T_END_HEREDOC]);
                }
                if ($this->is('#[')) {
                    return $skip(['#[', '['], [']']);
                }
                if ($this->is('[')) {
                    return $skip(['#[', '['], [']']);
                }
                if ($this->is('${')) {
                    return $skip(['${'], ['}']); // @codeCoverageIgnore deprecated php8.2
                }
                if ($this->is('{')) {
                    return $skip(['{', '"'], ['}']);
                }
                if ($this->is('(')) {
                    return $skip(['('], [')']);
                }

                throw new \DomainException(sprintf("token is not pairable(line:%d, pos:%d, '%s')", $this->line, $this->pos, $this->text));
            }

            public function contents(?int $end = null): string
            {
                $end ??= $this->end()->index;
                return implode('', array_column(array_slice($this->tokens, $this->index, $end - $this->index + 1), 'text'));
            }

            public function resolve($ref): string
            {
                $var_export = fn($v) => var_export($v, true);
                $prev = $this->prev();
                $next = $this->next();

                $text = $this->text;
                if ($this->id === T_STRING) {
                    $namespaces = [$ref->getNamespaceName()];
                    if ($ref instanceof \ReflectionFunctionAbstract) {
                        $namespaces[] = $ref->getClosureScopeClass()?->getNamespaceName();
                    }
                    if ($prev->id === T_NEW || $prev->id === T_ATTRIBUTE || $next->id === T_DOUBLE_COLON || $next->id === T_VARIABLE || $next->text === '{') {
                        $text = \ryunosuke\castella\Utility::namespace_resolve($text, $ref->getFileName(), 'alias') ?? $text;
                    }
                    elseif ($next->text === '(') {
                        $text = \ryunosuke\castella\Utility::namespace_resolve($text, $ref->getFileName(), 'function') ?? $text;
                        // 関数・定数は use しなくてもグローバルにフォールバックされる（=グローバルと名前空間の区別がつかない）
                        foreach ($namespaces as $namespace) {
                            if (!function_exists($text) && function_exists($nstext = "\\$namespace\\$text")) {
                                $text = $nstext;
                                break;
                            }
                        }
                    }
                    else {
                        $text = \ryunosuke\castella\Utility::namespace_resolve($text, $ref->getFileName(), 'const') ?? $text;
                        // 関数・定数は use しなくてもグローバルにフォールバックされる（=グローバルと名前空間の区別がつかない）
                        foreach ($namespaces as $namespace) {
                            if (!\ryunosuke\castella\Utility::const_exists($text) && \ryunosuke\castella\Utility::const_exists($nstext = "\\$namespace\\$text")) {
                                $text = $nstext;
                                break;
                            }
                        }
                    }
                }

                // マジック定数の解決
                if ($this->id === T_DIR) {
                    $text = $var_export(dirname($ref->getFileName()));
                }
                if ($this->id === T_FILE) {
                    $text = $var_export($ref->getFileName());
                }
                if ($this->id === T_NS_C) {
                    $text = $var_export($ref->getNamespaceName());
                }
                return $text;
            }

            private function sibling(int $step, $condition)
            {
                if (is_array($condition) || !\ryunosuke\castella\Utility::is_callback($condition)) {
                    $condition = fn($token) => $token->is($condition);
                }
                for ($i = $this->index + $step; isset($this->tokens[$i]); $i += $step) {
                    if ($condition($this->tokens[$i])) {
                        return $this->tokens[$i];
                    }
                }
                return null;
            }
        };

        $tokens = $PhpToken::tokenize($code, $flags);
        foreach ($tokens as $i => $token) {
            $token->tokens = $tokens;
            $token->index = $i;
        }
        return $tokens;
    }

    /**
     * callable のコードブロックを返す
     *
     * 返り値は2値の配列。0番目の要素が定義部、1番目の要素が処理部を表す。
     *
     * Example:
     * ```php
     * list($meta, $body) = callable_code(function (...$args) {return true;});
     * that($meta)->isSame('function (...$args)');
     * that($body)->isSame('{return true;}');
     *
     * // ReflectionFunctionAbstract を渡しても動作する
     * list($meta, $body) = callable_code(new \ReflectionFunction(function (...$args) {return true;}));
     * that($meta)->isSame('function (...$args)');
     * that($body)->isSame('{return true;}');
     * ```
     *
     * @package ryunosuke\Functions\Package\reflection
     *
     * @param callable|\ReflectionFunctionAbstract $callable コードを取得する callable
     * @param bool $return_token true にすると生のトークン配列で返す
     * @return array ['定義部分', '{処理コード}']
     */
    public static function callable_code($callable, bool $return_token = false)
    {
        $ref = $callable instanceof \ReflectionFunctionAbstract ? $callable : \ryunosuke\castella\Utility::reflect_callable($callable);
        $contents = file($ref->getFileName());
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $codeblock = implode('', array_slice($contents, $start - 1, $end - $start + 1));

        $tokens = \ryunosuke\castella\Utility::php_tokens("<?php $codeblock");

        $begin = $tokens[0]->next([T_FUNCTION, T_FN]);
        $close = $begin->next(['{', T_DOUBLE_ARROW]);

        if ($begin->is(T_FN)) {
            $meta = array_slice($tokens, $begin->index, $close->prev()->index - $begin->index + 1);
            $temp = $close->find([';', ',']);
            // アロー関数は終了トークンが明確ではない
            // - $x = fn() => 123;         // セミコロン
            // - $x = fn() => [123];       // セミコロンであって ] ではない
            // - $x = [fn() => 123, null]; // こうだとカンマになるし
            // - $x = [fn() => 123];       // こうだと ] になる
            // しっかり実装できなくもないが、（多分）戻り読みが必要なのでここでは構文チェックをパスするまでループする実装とした
            while (true) {
                $test = array_slice($tokens, $close->next()->index, $temp->index - $close->next()->index);
                $text = implode('', array_column($test, 'text'));
                try {
                    /** @noinspection PhpExpressionResultUnusedInspection */
                    token_get_all("<?php $text;", TOKEN_PARSE);
                    break;
                }
                catch (\Throwable) {
                    $temp = $temp->prev();
                }
            }
            $body = array_slice($tokens, $close->next()->index, $temp->index - $close->next()->index);
        }
        else {
            $meta = array_slice($tokens, $begin->index, $close->index - $begin->index);
            $body = $close->end();
            $body = array_slice($tokens, $close->index, $body->index - $close->index + 1);
        }

        if ($return_token) {
            return [$meta, $body];
        }

        return [trim(implode('', array_column($meta, 'text'))), trim(implode('', array_column($body, 'text')))];
    }

    /**
     * callable から ReflectionFunctionAbstract を生成する
     *
     * 実際には ReflectionFunctionAbstract を下記の独自拡張した Reflection クラスを返す（メソッドのオーバーライド等はしていないので完全互換）。
     * - __invoke: 元となったオブジェクトを $this として invoke する（関数・クロージャは invoke と同義）
     * - call: 実行 $this を指定して invoke する（クロージャ・メソッドのみ）
     *   - 上記二つは __call/__callStatic のメソッドも呼び出せる
     * - getDeclaration: 宣言部のコードを返す
     * - getCode: 定義部のコードを返す
     * - isAnonymous: 無名関数なら true を返す（8.2 の isAnonymous 互換）
     * - isStatic: $this バインド可能かを返す（クロージャのみ）
     * - getUsedVariables: use している変数配列を返す（クロージャのみ）
     * - getClosure: 元となったオブジェクトを $object としたクロージャを返す（メソッドのみ）
     *   - 上記二つは __call/__callStatic のメソッドも呼び出せる
     * - getTraitMethod: トレイト側のリフレクションを返す（メソッドのみ）
     *
     * Example:
     * ```php
     * that(reflect_callable('sprintf'))->isInstanceOf(\ReflectionFunction::class);
     * that(reflect_callable('\Closure::bind'))->isInstanceOf(\ReflectionMethod::class);
     *
     * $x = 1;
     * $closure = function ($a, $b) use (&$x) { return $a + $b; };
     * $reflection = reflect_callable($closure);
     * // 単純実行
     * that($reflection(1, 2))->is(3);
     * // 無名クラスを $this として実行
     * that($reflection->call(new class(){}, 1, 2))->is(3);
     * // 宣言部を返す
     * that($reflection->getDeclaration())->is('function ($a, $b) use (&$x)');
     * // 定義部を返す
     * that($reflection->getCode())->is('{ return $a + $b; }');
     * // static か返す
     * that($reflection->isStatic())->is(false);
     * // use 変数を返す
     * that($reflection->getUsedVariables())->is(['x' => 1]);
     * ```
     *
     * @package ryunosuke\Functions\Package\reflection
     *
     * @param callable $callable 対象 callable
     * @return \ReflectCallable|\ReflectionFunction|\ReflectionMethod リフレクションインスタンス
     */
    public static function reflect_callable($callable)
    {
        // callable チェック兼 $call_name 取得
        if (!is_callable($callable, true, $call_name)) {
            throw new \InvalidArgumentException("'$call_name' is not callable");
        }

        if (is_string($call_name) && strpos($call_name, '::') === false) {
            return new class($callable) extends \ReflectionFunction {
                private $definition;

                public function __invoke(...$args): mixed
                {
                    return $this->invoke(...$args);
                }

                public function getDeclaration(): string
                {
                    return ($this->definition ??= \ryunosuke\castella\Utility::callable_code($this))[0];
                }

                public function getCode(): string
                {
                    return ($this->definition ??= \ryunosuke\castella\Utility::callable_code($this))[1];
                }

                public function isAnonymous(): bool
                {
                    return false;
                }
            };
        }
        elseif ($callable instanceof \Closure) {
            return new class($callable) extends \ReflectionFunction {
                private $callable;
                private $definition;

                public function __construct($function)
                {
                    parent::__construct($function);

                    $this->callable = $function;
                }

                public function __invoke(...$args): mixed
                {
                    return $this->invoke(...$args);
                }

                public function call($newThis = null, ...$args): mixed
                {
                    return ($this->callable)->call($newThis ?? $this->getClosureThis(), ...$args);
                }

                public function getDeclaration(): string
                {
                    return ($this->definition ??= \ryunosuke\castella\Utility::callable_code($this))[0];
                }

                public function getCode(): string
                {
                    return ($this->definition ??= \ryunosuke\castella\Utility::callable_code($this))[1];
                }

                public function isAnonymous(): bool
                {
                    if (method_exists(\ReflectionFunction::class, 'isAnonymous')) {
                        return parent::isAnonymous(); // @codeCoverageIgnore
                    }

                    return strpos($this->name, '{closure}') !== false;
                }

                public function isStatic(): bool
                {
                    return !\ryunosuke\castella\Utility::is_bindable_closure($this->callable);
                }

                public function getUsedVariables(): array
                {
                    if (method_exists(\ReflectionFunction::class, 'getClosureUsedVariables')) {
                        return parent::getClosureUsedVariables(); // @codeCoverageIgnore
                    }

                    $uses = \ryunosuke\castella\Utility::object_properties($this->callable);
                    unset($uses['this']);
                    return $uses;
                }
            };
        }
        else {
            [$class, $method] = explode('::', $call_name, 2);
            // for タイプ 5: 相対指定による静的クラスメソッドのコール (PHP 5.3.0 以降)
            if (strpos($method, 'parent::') === 0) {
                [, $method] = explode('::', $method);
                $class = get_parent_class($class);
            }

            $called_name = '';
            if (!method_exists(is_array($callable) && is_object($callable[0]) ? $callable[0] : $class, $method)) {
                $called_name = $method;
                $method = is_array($callable) && is_object($callable[0]) ? '__call' : '__callStatic';
            }

            return new class($class, $method, $callable, $called_name) extends \ReflectionMethod {
                private $callable;
                private $call_name;
                private $definition;

                public function __construct($class, $method, $callable, $call_name)
                {
                    parent::__construct($class, $method);

                    $this->setAccessible(true); // 8.1 はデフォルトで true になるので模倣する
                    $this->callable = $callable;
                    $this->call_name = $call_name;
                }

                public function __invoke(...$args): mixed
                {
                    if ($this->call_name) {
                        $args = [$this->call_name, $args];
                    }
                    return $this->invoke($this->isStatic() ? null : $this->callable[0], ...$args);
                }

                public function call($newThis = null, ...$args): mixed
                {
                    if ($this->call_name) {
                        $args = [$this->call_name, $args];
                    }
                    return $this->getClosure($newThis ?? ($this->isStatic() ? null : $this->callable[0]))(...$args);
                }

                public function getDeclaration(): string
                {
                    return ($this->definition ??= \ryunosuke\castella\Utility::callable_code($this))[0];
                }

                public function getCode(): string
                {
                    return ($this->definition ??= \ryunosuke\castella\Utility::callable_code($this))[1];
                }

                public function isAnonymous(): bool
                {
                    return false;
                }

                public function getClosure(?object $object = null): \Closure
                {
                    $name = strtolower($this->name);

                    if ($this->isStatic()) {
                        if ($name === '__callstatic') {
                            return \Closure::fromCallable([$this->class, $this->call_name]);
                        }
                        return parent::getClosure();
                    }

                    $object ??= $this->callable[0];
                    if ($name === '__call') {
                        return \Closure::fromCallable([$object, $this->call_name]);
                    }
                    return parent::getClosure($object);
                }

                public function getTraitMethod(): ?\ReflectionMethod
                {
                    $name = strtolower($this->name);
                    $class = $this->getDeclaringClass();
                    $aliases = array_change_key_case($class->getTraitAliases(), CASE_LOWER);

                    if (!isset($aliases[$name])) {
                        if ($this->getFileName() === $class->getFileName()) {
                            return null;
                        }
                        else {
                            return $this;
                        }
                    }

                    [$tname, $mname] = explode('::', $aliases[$name]);
                    $result = new self($tname, $mname, $this->callable, $this->call_name);

                    // alias を張ったとしても自身で再宣言はエラーなく可能で、その場合自身が採用されるようだ
                    if (false
                        || $this->getFileName() !== $result->getFileName()
                        || $this->getStartLine() !== $result->getStartLine()
                        || $this->getEndLine() !== $result->getEndLine()
                    ) {
                        return null;
                    }

                    return $result;
                }
            };
        }
    }

    /**
     * strcat の空文字回避版
     *
     * 基本は strcat と同じ。ただし、**引数の内1つでも空文字を含むなら空文字を返す**。
     * さらに*引数の内1つでも null を含むなら null を返す**。
     *
     * 「プレフィックスやサフィックスを付けたいんだけど、空文字の場合はそのままで居て欲しい」という状況はまれによくあるはず。
     * コードで言えば `strlen($string) ? 'prefix-' . $string : '';` のようなもの。
     * 可変引数なので 端的に言えば mysql の CONCAT みたいな動作になる。
     *
     * ```php
     * that(concat('prefix-', 'middle', '-suffix'))->isSame('prefix-middle-suffix');
     * that(concat('prefix-', '', '-suffix'))->isSame('');
     * that(concat('prefix-', null, '-suffix'))->isSame(null);
     * ```
     *
     * @package ryunosuke\Functions\Package\strings
     *
     * @param ?string ...$variadic 結合する文字列（可変引数）
     * @return ?string 結合した文字列
     */
    public static function concat(...$variadic)
    {
        if (count(array_filter($variadic, 'is_null')) > 0) {
            return null;
        }
        $result = '';
        foreach ($variadic as $s) {
            if (strlen($s) === 0) {
                return '';
            }
            $result .= $s;
        }
        return $result;
    }

    /**
     * 文字列を名前空間とローカル名に区切ってタプルで返す
     *
     * class_namespace/class_shorten や function_shorten とほぼ同じだが下記の違いがある。
     *
     * - あくまで文字列として処理する
     *     - 例えば class_namespace は get_class されるが、この関数は（いうなれば） strval される
     * - \\ を trim しないし、特別扱いもしない
     *     - `ns\\hoge` と `\\ns\\hoge` で返り値が微妙に異なる
     *     - `ns\\` のような場合は名前空間だけを返す
     *
     * Example:
     * ```php
     * that(namespace_split('ns\\hoge'))->isSame(['ns', 'hoge']);
     * that(namespace_split('hoge'))->isSame(['', 'hoge']);
     * that(namespace_split('ns\\'))->isSame(['ns', '']);
     * that(namespace_split('\\hoge'))->isSame(['', 'hoge']);
     * ```
     *
     * @package ryunosuke\Functions\Package\strings
     *
     * @param string $string 対象文字列
     * @return array [namespace, localname]
     */
    public static function namespace_split(?string $string)
    {
        $pos = strrpos($string, '\\');
        if ($pos === false) {
            return ['', $string];
        }
        return [substr($string, 0, $pos), substr($string, $pos + 1)];
    }

    /**
     * url safe な base64_encode
     *
     * れっきとした RFC があるのかは分からないが '+' => '-', '/' => '_' がデファクトだと思うのでそのようにしてある。
     * パディングの = も外す。
     *
     * @package ryunosuke\Functions\Package\url
     *
     * @param string $string 変換元文字列
     * @return string base64url 文字列
     */
    public static function base64url_encode($string)
    {
        return rtrim(strtr(base64_encode($string), ['+' => '-', '/' => '_']), '=');
    }

    /**
     * シンプルにキャッシュする
     *
     * この関数は get/set/delete を兼ねる。
     * キャッシュがある場合はそれを返し、ない場合は $provider を呼び出してその結果をキャッシュしつつそれを返す。
     *
     * $provider に null を与えるとキャッシュの削除となる。
     *
     * Example:
     * ```php
     * $provider = fn() => rand();
     * // 乱数を返す処理だが、キャッシュされるので同じ値になる
     * $rand1 = cache('rand', $provider);
     * $rand2 = cache('rand', $provider);
     * that($rand1)->isSame($rand2);
     * // $provider に null を与えると削除される
     * cache('rand', null);
     * $rand3 = cache('rand', $provider);
     * that($rand1)->isNotSame($rand3);
     * ```
     *
     * @package ryunosuke\Functions\Package\utility
     * @deprecated delete in future scope
     * @codeCoverageIgnore
     *
     * @param string $key キャッシュのキー
     * @param ?callable $provider キャッシュがない場合にコールされる callable
     * @param ?string $namespace 名前空間
     * @return mixed キャッシュ
     */
    public static function cache($key, $provider, $namespace = null)
    {
        static $cacheobject;
        $cacheobject ??= new class(\ryunosuke\castella\Utility::function_configure('cachedir')) {
            const CACHE_EXT = '.php-cache';

            /** @var string キャッシュディレクトリ */
            private $cachedir;

            /** @var array 内部キャッシュ */
            private $cache;

            /** @var array 変更感知配列 */
            private $changed;

            public function __construct($cachedir)
            {
                $this->cachedir = $cachedir;
                $this->cache = [];
                $this->changed = [];
            }

            public function __destruct()
            {
                // 変更されているもののみ保存
                foreach ($this->changed as $namespace => $dummy) {
                    $filepath = $this->cachedir . '/' . rawurlencode($namespace) . self::CACHE_EXT;
                    $content = "<?php\nreturn " . var_export($this->cache[$namespace], true) . ";\n";

                    $temppath = tempnam(sys_get_temp_dir(), 'cache');
                    if (file_put_contents($temppath, $content) !== false) {
                        @chmod($temppath, 0644);
                        if (!@rename($temppath, $filepath)) {
                            @unlink($temppath); // @codeCoverageIgnore
                        }
                    }
                }
            }

            public function has($namespace, $key)
            {
                // ファイルから読み込む必要があるので get しておく
                $this->get($namespace, $key);
                return array_key_exists($key, $this->cache[$namespace]);
            }

            public function get($namespace, $key)
            {
                // 名前空間自体がないなら作る or 読む
                if (!isset($this->cache[$namespace])) {
                    $nsarray = [];
                    $cachpath = $this->cachedir . '/' . rawurlencode($namespace) . self::CACHE_EXT;
                    if (file_exists($cachpath)) {
                        $nsarray = require $cachpath;
                    }
                    $this->cache[$namespace] = $nsarray;
                }

                return $this->cache[$namespace][$key] ?? null;
            }

            public function set($namespace, $key, $value)
            {
                // 新しい値が来たら変更フラグを立てる
                if (!isset($this->cache[$namespace]) || !array_key_exists($key, $this->cache[$namespace]) || $this->cache[$namespace][$key] !== $value) {
                    $this->changed[$namespace] = true;
                }

                $this->cache[$namespace][$key] = $value;
            }

            public function delete($namespace, $key)
            {
                $this->changed[$namespace] = true;
                unset($this->cache[$namespace][$key]);
            }

            public function clear()
            {
                // インメモリ情報をクリアして・・・
                $this->cache = [];
                $this->changed = [];

                // ファイルも消す
                foreach (glob($this->cachedir . '/*' . self::CACHE_EXT) as $file) {
                    unlink($file);
                }
            }
        };

        // flush (for test)
        if ($key === null) {
            if ($provider === null) {
                $cacheobject->clear();
            }
            $cacheobject = null;
            return;
        }

        $namespace ??= __FILE__;

        $exist = $cacheobject->has($namespace, $key);
        if ($provider === null) {
            $cacheobject->delete($namespace, $key);
            return $exist;
        }
        if (!$exist) {
            $cacheobject->set($namespace, $key, $provider());
        }
        return $cacheobject->get($namespace, $key);
    }

    /**
     * 本ライブラリの設定を行う
     *
     * 各関数の挙動を変えたり、デフォルトオプションを設定できる。
     *
     * @package ryunosuke\Functions\Package\utility
     *
     * @param array|?string $option 設定。文字列指定時はその値を返す
     * @return array|string 設定値
     */
    public static function function_configure($option)
    {
        static $config = [];

        // default
        $config['cachedir'] ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rf' . DIRECTORY_SEPARATOR . strtr(__NAMESPACE__, ['\\' => '%']);
        $config['storagedir'] ??= DIRECTORY_SEPARATOR === '/' ? '/var/tmp/rf/' . strtr(__NAMESPACE__, ['\\' => '%']) : (getenv('ALLUSERSPROFILE') ?: sys_get_temp_dir()) . '\\rf\\' . strtr(__NAMESPACE__, ['\\' => '%']);
        $config['placeholder'] ??= '';
        $config['var_stream'] ??= 'VarStreamV010000';
        $config['memory_stream'] ??= 'MemoryStreamV010000';
        $config['array.variant'] ??= false;
        $config['chain.version'] ??= 2;
        $config['chain.nullsafe'] ??= false;
        $config['process.autoload'] ??= [];
        $config['datetime.class'] ??= \DateTimeImmutable::class;

        // setting
        if (is_array($option)) {
            foreach ($option as $name => $entry) {
                $option[$name] = $config[$name] ?? null;
                switch ($name) {
                    default:
                        $config[$name] = $entry;
                        break;
                    case 'cachedir':
                    case 'storagedir':
                        $entry ??= $config[$name];
                        if (!file_exists($entry)) {
                            @mkdir($entry, 0777 & (~umask()), true);
                        }
                        $config[$name] = realpath($entry);
                        break;
                    case 'placeholder':
                        if (strlen($entry)) {
                            $entry = ltrim($entry[0] === '\\' ? $entry : __NAMESPACE__ . '\\' . $entry, '\\');
                            if (!defined($entry)) {
                                define($entry, tmpfile() ?: [] ?: '' ?: 0.0 ?: null ?: false);
                            }
                            if (!\ryunosuke\castella\Utility::is_resourcable(constant($entry))) {
                                // もしリソースじゃないと一意性が保てず致命的になるので例外を投げる
                                throw new \RuntimeException('placeholder is not resource'); // @codeCoverageIgnore
                            }
                            $config[$name] = $entry;
                        }
                        break;
                }
            }
            return $option;
        }

        // getting
        if ($option === null) {
            return $config;
        }
        if (is_string($option)) {
            switch ($option) {
                default:
                    return $config[$option] ?? null;
                case 'cachedir':
                case 'storagedir':
                    $dirname = $config[$option];
                    if (!file_exists($dirname)) {
                        @mkdir($dirname, 0777 & (~umask()), true); // @codeCoverageIgnore
                    }
                    return realpath($dirname);
            }
        }

        throw new \InvalidArgumentException(sprintf('$option is unknown type(%s)', gettype($option)));
    }

    /**
     * キーが json 化されてファイルシステムに永続化される ArrayAccess を返す
     *
     * 非常にシンプルで PSR-16 も実装せず、TTL もクリア手段も（基本的には）存在しない。
     * ArrayAccess なので `$storage['hoge'] ??= something()` として使うのがほぼ唯一の利用法。
     * その仕様・利用上、値として null を使用することはできない（使用した場合の動作は未定義とする）。
     *
     * キーに指定できるのは json_encode 可能なもののみ。
     * 値に指定できるのは var_export 可能なもののみ。
     * 上記以外を与えたときの動作は未定義。
     *
     * 得てして簡単な関数・メソッドのメモ化や内部的なキャッシュに使用する。
     *
     * Example:
     * ```php
     * // ??= を使えば「無かったら値を、有ったらそれを」を単純に実現できる
     * $storage = json_storage();
     * that($storage['key'] ??= (fn() => 123)())->is(123);
     * that($storage['key'] ??= (fn() => 456)())->is(123);
     * // 引数に与えた prefix で別空間になる
     * $storage = json_storage('other');
     * that($storage['key'] ??= (fn() => 789)())->is(789);
     * ```
     *
     * @package ryunosuke\Functions\Package\utility
     *
     * @param string $directory 永続化ディレクトリ
     * @return \ArrayObject
     */
    public static function json_storage(string $prefix = 'global')
    {
        $cachedir = \ryunosuke\castella\Utility::function_configure('cachedir') . '/' . strtr(__FUNCTION__, ['\\' => '%']);
        if (!file_exists($cachedir)) {
            @mkdir($cachedir, 0777, true);
        }

        static $objects = [];
        return $objects[$prefix] ??= new class("$cachedir/" . strtr($prefix, ['\\' => '%', '/' => '-'])) extends \ArrayObject {
            public function __construct(private string $directory)
            {
                parent::__construct();
            }

            public function offsetExists(mixed $key): bool
            {
                return $this->offsetGet($key) !== null;
            }

            public function offsetGet(mixed $key): mixed
            {
                $json = $this->json($key);

                // 有るならそれでよい
                if (parent::offsetExists($json)) {
                    return parent::offsetGet($json);
                }

                // 無くてもストレージにある可能性がある
                $filename = $this->filename($json);
                clearstatcache(true, $filename);
                if (file_exists($filename)) {
                    [$k, $v] = include $filename;
                    // hash 化してるので万が一競合すると異なるデータを返してしまう
                    if ($k !== $key) {
                        return null; // @codeCoverageIgnore
                    }
                    // ストレージに有ったら内部キャッシュしてそれを使う
                    parent::offsetSet($json, $v);
                    return $v;
                }

                return null;
            }

            public function offsetSet(mixed $key, mixed $value): void
            {
                $json = $this->json($key);

                // 値が変化したらストレージにも保存
                if (!parent::offsetExists($json) || parent::offsetGet($json) !== $value) {
                    assert(\ryunosuke\castella\Utility::is_exportable($value));
                    $filename = $this->filename($json);
                    if ($value === null) {
                        opcache_invalidate($filename, true);
                        @unlink($filename);
                    }
                    else {
                        file_put_contents($filename, '<?php return ' . var_export([$key, $value], true) . ';', LOCK_EX);
                    }
                }

                parent::offsetSet($json, $value);
            }

            public function offsetUnset(mixed $key): void
            {
                $this->offsetSet($key, null);
            }

            private function json(mixed $data): string
            {
                assert((function () use ($data) {
                    $tmp = [$data];
                    array_walk_recursive($tmp, function ($value) {
                        if (\ryunosuke\castella\Utility::is_resourcable($value)) {
                            throw new \Exception("\$value is resource");
                        }
                        if (is_object($value) && (!$value instanceof \JsonSerializable && get_class($value) !== \stdClass::class)) {
                            throw new \Exception("\$value is not JsonSerializable");
                        }
                    });
                    return true;
                })());
                return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }

            private function filename(string $json): string
            {
                $filename = \ryunosuke\castella\Utility::base64url_encode(implode("\n", [
                    hash('fnv164', $json, true),
                    hash('crc32', $json, true),
                ]));
                return "{$this->directory}-$filename.php-cache";
            }
        };
    }

    /**
     * 値が var_export で出力可能か検査する
     *
     * 「出力可能」とは「意味のある出力」を意味する。
     * 例えば set_state のないオブジェクトはエラーなく set_state コール形式で出力されるが意味のある出力ではない。
     * リソース型はエラーなく NULL で出力されるが意味のある出力ではない。
     * 循環参照は出力できるものの warning が出てかつ循環は切れるため意味のある出力ではない。
     *
     * Example:
     * ```php
     * that(is_primitive(null))->isTrue();
     * that(is_primitive(false))->isTrue();
     * that(is_primitive(123))->isTrue();
     * that(is_primitive(STDIN))->isTrue();
     * that(is_primitive(new \stdClass))->isFalse();
     * that(is_primitive(['array']))->isFalse();
     * ```
     *
     * @package ryunosuke\Functions\Package\var
     *
     * @param mixed $var 調べる値
     * @return bool 出力可能なら true
     */
    public static function is_exportable($var): bool
    {
        // スカラー/NULL は OK
        if (is_scalar($var) || is_null($var)) {
            return true;
        }

        // リソース型の変数は、この関数ではエクスポートする事ができません
        if (\ryunosuke\castella\Utility::is_resourcable($var)) {
            return false;
        }

        // var_export() では循環参照を扱うことができません
        if (\ryunosuke\castella\Utility::is_recursive($var)) {
            return false;
        }

        // 配列に制限はない。それゆえに全要素を再帰的に見なければならない
        if (is_array($var)) {
            foreach ($var as $v) {
                if (!\ryunosuke\castella\Utility::is_exportable($v)) {
                    return false;
                }
            }
            return true;
        }

        if (is_object($var)) {
            // 無名クラスは非常に特殊で、出力は class@anonymous{filename}:123$456::__set_state(...) のようになる
            // set_state さえ実装してれば復元可能に思えるが php コードとして不正なのでそのまま実行するとシンタックスエラーになる
            // 'class@anonymous{filename}:123$456'::__set_state(...) のようにクオートすれば実行可能になるが、それは標準 var_export の動作ではない
            // 復元する側がクオートして読み込み…とすれば復元可能だが、そもそもクラスがロードされている保証もない
            // これらのことを考慮するなら「意味のある出力」ではないとみなした方が手っ取り早い
            if ((new \ReflectionClass($var))->isAnonymous()) {
                return false;
            }
            // var_export() が生成する PHP を評価できるようにするためには、処理対象のすべてのオブジェクトがマジックメソッド __set_state を実装している必要があります
            if (method_exists($var, '__set_state')) {
                return true;
            }
            // これの唯一の例外は stdClass です。 stdClass は、配列をオブジェクトにキャストした形でエクスポートされます
            if (get_class($var) === \stdClass::class) {
                return true;
            }
            // マニュアルに記載はないが enum は export できる
            if ($var instanceof \UnitEnum) {
                return true;
            }
            return false;
        }
    }

    /**
     * 値が複合型でないか検査する
     *
     * 「複合型」とはオブジェクトと配列のこと。
     * つまり
     *
     * - is_scalar($var) || is_null($var) || is_resource($var)
     *
     * と同義（!is_array($var) && !is_object($var) とも言える）。
     *
     * Example:
     * ```php
     * that(is_primitive(null))->isTrue();
     * that(is_primitive(false))->isTrue();
     * that(is_primitive(123))->isTrue();
     * that(is_primitive(STDIN))->isTrue();
     * that(is_primitive(new \stdClass))->isFalse();
     * that(is_primitive(['array']))->isFalse();
     * ```
     *
     * @package ryunosuke\Functions\Package\var
     *
     * @param mixed $var 調べる値
     * @return bool 複合型なら false
     */
    public static function is_primitive($var)
    {
        return is_scalar($var) || is_null($var) || \ryunosuke\castella\Utility::is_resourcable($var);
    }

    /**
     * 変数が再帰参照を含むか調べる
     *
     * Example:
     * ```php
     * // 配列の再帰
     * $array = [];
     * $array['recursive'] = &$array;
     * that(is_recursive($array))->isTrue();
     * // オブジェクトの再帰
     * $object = new \stdClass();
     * $object->recursive = $object;
     * that(is_recursive($object))->isTrue();
     * ```
     *
     * @package ryunosuke\Functions\Package\var
     *
     * @param mixed $var 調べる値
     * @return bool 再帰参照を含むなら true
     */
    public static function is_recursive($var)
    {
        $core = function ($var, $parents) use (&$core) {
            // 複合型でないなら間違いなく false
            if (\ryunosuke\castella\Utility::is_primitive($var)) {
                return false;
            }

            // 「親と同じ子」は再帰以外あり得ない。よって === で良い（オブジェクトに関してはそもそも等値比較で絶対に一致しない）
            // sql_object_hash とか serialize でキーに保持して isset の方が速いか？
            // → ベンチ取ったところ in_array の方が10倍くらい速い。多分生成コストに起因
            // raw な比較であれば瞬時に比較できるが、isset だと文字列化が必要でかなり無駄が生じていると考えられる
            foreach ($parents as $parent) {
                if ($parent === $var) {
                    return true;
                }
            }

            // 全要素を再帰的にチェック
            $parents[] = $var;
            foreach ($var as $v) {
                if ($core($v, $parents)) {
                    return true;
                }
            }
            return false;
        };
        return $core($var, []);
    }

    /**
     * 閉じたリソースでも true を返す is_resource
     *
     * マニュアル（ https://www.php.net/manual/ja/function.is-resource.php ）に記載の通り、 isresource は閉じたリソースで false を返す。
     * リソースはリソースであり、それでは不便なこともあるので、閉じていようとリソースなら true を返す関数。
     *
     * Example:
     * ```php
     * // 閉じたリソースを用意
     * $resource = tmpfile();
     * fclose($resource);
     * // is_resource は false を返すが・・・
     * that(is_resource($resource))->isFalse();
     * // is_resourcable は true を返す
     * that(is_resourcable($resource))->isTrue();
     * ```
     *
     * @package ryunosuke\Functions\Package\var
     *
     * @param mixed $var 調べる値
     * @return bool リソースなら true
     */
    public static function is_resourcable($var)
    {
        if (is_resource($var)) {
            return true;
        }
        // もっといい方法があるかもしれないが、簡単に調査したところ gettype するしか術がないような気がする
        if (strpos(gettype($var), 'resource') === 0) {
            return true;
        }
        return false;
    }
}
