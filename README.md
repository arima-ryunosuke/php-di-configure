# php configuration and DI container

## Description

設定ファイルをベースとした DI コンテナです。
下記の機能があります。

- コンストラクタインジェクション
- フィールドインジェクション
- オートワイヤリング
- 上記に伴う循環参照解決

## Install

```json
{
  "require": {
    "ryunosuke/castella": "dev-master"
  }
}
```

## Concept

- **設定ファイルベース**
    - 「DI コンテナ（＋設定ファイル）」ではなく「設定ファイル（＋DI コンテナ）」です
    - つまり「DI コンテナを設定ファイルのように使いたい」ではなく「設定ファイルを DI コンテナのように使いたい」が基本コンセプトです
- **ミニマム・コンパクト**
    - 複雑な依存は廃し、完全無依存（除 psr11）＋単一ファイルで構成されます
    - 最悪の場合は「コピペでコードベースを変更できる」を仮定しています（依存が大量でファイルが100を超えていたりするとこうは行かない）
- **シンプル**
    - 「あらゆる注入をサポートする」のではなく、「DI コンテナに合わせてクラス設計する」という方針です。あまり無節操に外部のオブジェクトを取り込むような設計ではありません
    - サポートする機能は「コンストラクタインジェクション」「フィールドインジェクション」「オートワイヤリング」だけです
    - 「セッターインジェクション」「アノテーション」「メソッドコール」などは実装されていませんし、今後実装する予定もありません
    - コンパイル・変換・キャッシュなどはありません。十分に高速です
- **簡潔**
    - 設定ファイルの記法は「値を書く」か「クロージャを書く」かだけです（利便性のための糖衣構文はある）
    - 値の場合はそのまま活かされますし、クロージャの場合は遅延実行されます

## Spec

- コンテナに設定するデータソースは配列（or 配列を返すファイル）のみです
    - 一応 `set` も用意してありますが、内部的には配列です
- 配列はキー単位でマージ（上書き）されます
    - 連想配列・連番配列の区別はありません。よって連番配列のマージは意図しない結果になることがあります
    - 完全に上書きされる配列を定義したい場合はクロージャで包むか array メソッドを使う必要があります
- 「値」とは「クロージャ以外のすべて」を指します。値は設定したものがそのまま返されます
- クロージャは例外なく遅延実行されます。つまり「必要になったその時」まで実行されません
    - よってエントリにクロージャを設定したい場合は「クロージャを返すクロージャ」を設定する必要があります
    - クロージャの値としての型は「型宣言による返り値の型」です。これによりクロージャを実行せずとも型の判定を可能にしています
    - 返り値の型を記述しないと void となり、依存関係の解決の対象外となります
    - static クロージャにすると毎回同じインスタンスを返します。 static ではない普通のクロージャは毎回生成して返します
- クロージャの引数は `(コンテナ, キー逆順)` で固定です
    - クロージャの引数で型検出はされない、ということです
    - この仕様は設定のコンテキストのすべてのクロージャに適用されます

## Usage

### 基本

このパッケージは端的に言えば「依存関係を解決できるコンフィグレーションライブラリ」です。
環境や開発者間での設定を吸収する、下記のようなユースケースを想定しています。

まず、下記のようなデフォルト設定があるとします。

```php
<?php
# $container->include('default.php');

/**
 * @var ryunosuke\castella\Container $this
 */

return [
    'env'      => [
        'name'      => 'local',
        'origin'    => 'http://localhost',
        'loglevel'  => LOG_INFO,
        'logdir'    => '/var/log/app',
        'rundir'    => '/var/run/app',
        'datadir'   => '/var/opt/app',
        'extension' => ['js', 'es', 'ts'],
    ],
    'database' => [
        'driver'        => 'pdo_mysql',
        'host'          => '127.0.0.1',
        'port'          => 3306,
        'dbname'        => 'app',
        'user'          => 'user',
        'password'      => 'password',
        'charset'       => 'utf8mb4',
        'driverOptions' => [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ],
    ],
    's3'       => [
        'config' => [
            'region'  => 'ap-northeast-1',
            'version' => 'latest',
        ],
        'client' => $this->static(S3Client::class),
    ],
    'storage'  => [
        'private' => static fn($c, $key) => new Storage($c['s3.client'], $key),
        'protect' => static fn($c, $key) => new Storage($c['s3.client'], $key),
        'public'  => static fn($c, $key) => new Storage($c['s3.client'], $key),
    ],
];
```

これは「アプリが動作するための最低限の動作 and 開発者向けの参考・スキーマ」のようなファイルです。
環境を問わず必ず読み込まれるようなベース設定です。
これだけだと設定に意味はなく、まともな動作はしない事が多いです。

次に環境変数やホスト名等に基づいて下記のような個別設定があるとします。

```php
<?php
# $container->include('myconfig.php');

/**
 * @var ryunosuke\castella\Container $this
 */

return [
    'env'      => [
        'origin'    => 'http://myself',
        'loglevel'  => LOG_DEBUG,
        'extension' => $this->array(['php']),
    ],
    'database' => [
        'host'          => 'docker-mysql',
        'driverOptions' => [
            \PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],
    's3'       => [
        'config' => [
            'credentials' => [
                'key'    => 'minio',
                'secret' => 'minio123',
            ],
            'endpoint'    => 'http://minio.localhost',
        ],
    ],
];
```

配列はマージされるため、「デフォルトから上書きしたいエントリ」だけを指定します。
上記ではデフォルトを尊重しつつ、「ログレベルは DEBUG にしたい」「DB は docker の mysql を使いたい」「S3 は minio を使いたい」といった形で必要なもののみ上書きしています。
これが「設定ファイルベース」たる所以です。

さらに storage 設定や S3 のクライアントなどは平易な記法で依存関係を解決しています。遅延実行＋キーの上書き機構により不必要な実行は一切行われませんし、インスタンスは使い回されます。
これが「設定ファイルを DI コンテナのように使いたい」たる所以です。

上記のとき、`env.extension` の値は `$this->array` しているため `['php']` であることに注意してください。
配列はマージされるため、この `$this->array` が無いと 0番目だけが上書きされた `['php', 'es', 'ts']` という値になってしまいます。
このように配列を完全に上書きしたい場合は `$this->array` を使用します。

また、 `storage` 以下のクロージャには自身へのキーの逆順が渡ってきています。
このように引数を活用するとキー値を使うときに重複した記述を避けることができます。

### コンストラクタ

コンストラクタで各種オプションを設定します。
ここで設定したオプションは変更できません。
オプションはすべて省略可能で下記はデフォルト値です。

```php
$container = new \ryunosuke\castella\Container([
    'delimiter'            => '.',
    'autowiring'           => true,
    'constructorInjection' => true,
    'propertyInjection'    => true,
    'resolver'             => $container->resolve(...),
]);
```

#### delimiter: string

アクセスするときの配列区切り文字を指定します。

```php
$container->extends([
    'a' => [
        'b' => [
            'c' => 'X',
        ],
    ],
]);
```

このようなとき、 `$container->get('a.b.c')` のようにアクセスできます。

#### autowiring: bool

未登録エントリにアクセスしようとしたときに型名で自動解決するかを指定します。

true にすると非エントリでもクラスが存在する場合はそのインスタンスが取得できます。
その際、依存関係は再帰的に解決されます。
false にすると非エントリを取得することはできません。

#### constructorInjection: bool

依存解決の際にコンストラクタの引数型・引数名を見てインジェクションするかを指定します。

true にすると後述の resolver で解決を試みます。
false にすると $arguments 引数で必須引数を完全にカバーする必要があります。

#### propertyInjection: bool

依存解決の際にプロパティの型・名前を見てインジェクションするかを指定します。

true にすると後述の resolver で解決を試みます。その際、nullable か初期化済みプロパティは対象外になります。
逆に言うと notnull で未初期化プロパティが解決されます。
false にするとプロパティを一切触らなくなります。

#### resolver: callable

依存関係の解決 callable を指定します。

callable のシグネチャは `(ReflectionParameter|ReflectionProperty $reflection): mixed` です。
ここで返した値が依存値として取得されます。

デフォルトでは下記の順で解決を試みます。

1. $type がスカラー値の場合、 引数名やプロパティ名の `_` を delimiter に置換したエントリ
2. $type の型文字列表現エントリ
3. $type と型が一致するエントリ（全走査）

「型が一致」とは一意であることを意味します。該当する型がなかったり複数ある場合は検出できません。

例えば `__construct(TypeName $this_is_arg)` の場合、まずエントリに `TypeName` という名前が登録されているか調べて、存在しなかった場合は全エントリから TypeName のインスタンスを走査します。
それでも解決できなかった場合は依存関係の解決に失敗し、例外を送出します。

### エントリ設定

下記の設定メソッドにおいて、動的・静的・遅延を問わず一度でも取得したエントリを上書きすることはできません。
取得済みエントリを上書きしようとすると例外を送出します。

#### extends(array $values): self

指定された配列をエントリとして取り込みます。
指定済みのキーは再帰的にマージ（上書き）されます。

キーを空白で区切ると左側がエントリ名、右側がエイリアスとして機能します。
エイリアスを指定するとそのエイリアス名でもアクセスすることができるようになります。

```php
$container->extends([
    'a' => [
        'b' => [
            'c abc' => 'X',
        ],
    ],
]);
```

上記のようにすると `a.b.c` でも `abc` でもアクセスできます。

#### include(string $filename): self

コンテナのコンテキストでファイルを読み込んで返り値を取り込みます。
実質的に `$container->extends((fn() => include $filename)->call($container))` と同義です。

#### set(string $id, $value): self

id 指定でエントリを設定します。
id は delimiter で潜ります。
`$container->set('a.b.c', 'X')` は実質的に `$container->extends(['a' => ['b' => ['c' => 'X']]])` と同義です。

### エントリ取得

#### has(string $id): bool

エントリが存在するか bool を返します。

#### get(string $id): mixed

エントリを取得します。
取得されたエントリはクロージャが解決されており、完全なる値を得ることができます。

`$container->get('')` のように空文字を与えると全エントリを配列で得られますが推奨しません。
これで得られる値は「配下すべてが解決済みの配列」であり、すべての遅延取得を無に帰す禁断の取得方法です。

'' に限らず、遅延取得を有効に活かすためには出来るだけ小さい単位で get するのがコツです。

#### fn(string $id): Closure

エントリを取得するクロージャを返します。
実質的に `fn() => $container->get($id)` と同義です。

「まだ使うか分からないのでクロージャでラップして取得したい」といった場合の糖衣構文として使えます。

#### ArrayAccess

ArrayAccess が実装されており、配列ライクなアクセスも可能です。
下記のように対応します。

- isset($container['key']) => $container->has('key')
- $val = $container['key'] => $val = $container->get('key')
- $container['key'] = $val => $container->set('key', $val)
- unset($container['key']) => 未サポート

### ユーティリティ

#### new(string $classname, array $arguments = []): object

与えられたクラス名のインスタンスを生成します。

依存関係は自動解決しますが、`$arguments` で与えるとそれが優先されます。
`$arguments` は名前付き引数・位置引数の両方をサポートします。

実質的に「依存関係にある引数を省略することができる new 演算子」となります。
つまり、下記の hoge1 と hoge2 は同義となります。

```php
<?php return [
    'hoge_arg1' => 1,
    'hoge_arg2' => 2,
    'hoge1'     => fn($c): Hoge => new Hoge($c['hoge_arg1'], $c['hoge_arg2']),
    'hoge2'     => fn($c): Hoge => $c->new(Hoge::class),
];
```

#### yield(string $classname, array $arguments = []): Closure

`$this->new` する型付きクロージャを返します。

設定ファイル内でインスタンスを自動解決させるときの糖衣構文です。
つまり、下記の hoge1 と hoge2 と hoge3 は同義となります。
また、`$arguments` 内のクロージャは実行時に解決されるため、hoge3 の引数のクロージャは定義時点では呼び出されません。

```php
<?php return [
    'hoge_arg1' => 1,
    'hoge_arg2' => 2,
    'hoge1'     => fn($c): Hoge => new Hoge($c['hoge_arg1'], $c['hoge_arg2']),
    'hoge2'     => $this->yield(Hoge::class),
    'hoge3'     => $this->yield(Hoge::class, [1 => $this->fn('hoge_arg2')]),
];
```

Hoge クラスのコンストラクタ引数が $hoge_arg1 だったり、 $hoge_arg2 が型付きで自動解決できる場合はこのような記述で Hoge インスタンスが（遅延）生成されます。
hoge3 は yield を使いつつ引数の遅延実行しています。
hoge3 はクロージャに囲まれておらず、設定ファイルのコンテキストで実行されるため、このようにクロージャ化しないと未定義もしくはエラーとなります。

このように依存関係が完全に閉じているなら yield を使用して自動に任せたほうが楽なこともあるでしょう。
また、個人的に返り値の Hoge 宣言を忘れることが多いです。返り値の型なしのクロージャは依存関係解決の対象外となるため、この記法は「単純にその型のインスタンスが欲しい」場合に有用です。

注意点として「特定の返り値型を持つクロージャ」を静的に定義するのが不可能なため、内部的には eval での実装になっています。
特に値の検証も行っていないため、 $classname に外部由来の値を与えるのは絶対にやめてください。
あと eval だと20倍近く速度差があるけど…まぁそんなに呼ばないと思うので大丈夫でしょう（100回書いても1ms未満なので常識の範囲内なら誤差レベル）。

#### static(string $classname, array $arguments = []): Closure

`yield` の static 版です。それ以外は全く同じです。
つまり、下記の hoge1 と hoge2 は同義となります。

```php
<?php return [
    'hoge_arg1' => 1,
    'hoge_arg2' => 2,
    'hoge1'     => static fn($c): Hoge => new Hoge($c['hoge_arg1'], $c['hoge_arg2']),
    'hoge2'     => $this->static(Hoge::class),
];
```

#### callable(callable $entry): Closure

与えられた callable をクロージャにするクロージャを返します。
仕様上、クロージャを値として設定するためには「クロージャを返すクロージャ」を定義する必要がありますが、その時の糖衣構文です。
つまり、下記の callable1 と callable2 は同義となります。

```php
<?php return [
    'callable1' => static fn(): Closure => fn() => 'something',
    'callable2' => $this->callable(fn() => 'something'),
];
```

自動的に static が付与されます。
これは「クロージャを返すクロージャは毎回生成する必要はないだろう」という前提に基づきます。

#### array(array $array): Closure

与えられた配列をそのまま返すクロージャを返します。
ただし、配列内のクロージャは実行時に解決されます。

配列をマージではなく完全に上書きしたいときの糖衣構文です。
つまり、下記の array1 と array2 は同義となります。

```php
<?php return [
    'array1' => fn(): array => ['php', 'js'],
    'array2' => $this->array(['php', 'js']),
];
```

パッと見はほぼ同じですが、yield/static と同様、型宣言を忘れることが多いのと、配列をマージしてしまうと都合が悪い時に有用です。

#### annotate(?string $filename): array

設定されている実際の型を元に phpstorm.meta.php 形式で文字列を吐き出します。

この機能は開発支援機能なので遅延クロージャはすべて解決されます。本運用時に呼ぶことは想定されていません。
$filename を与えるとそのファイルに `$container['hoge']` や `$container->get('hoge')` でコード補完が効くようになりかつ型を活かすことができるような map 配列が書き出されます。
返り値として名前の型配列を返します。

#### dump(string $id = ''): string

設定されている実際の型を元に指定 id をダンプします。

この機能は開発支援機能なので遅延クロージャはすべて解決されます。本運用時に呼ぶことは想定されていません。
オブジェクトの一意性やちょっとした値の確認に使います。

## Q&A

- Q.「なんで設定ファイルベース？」
    - A. 設定ファイルに記述することはコンテナにも記述しがちであり、分ける意義を見いだせなかったためです
- Q.「セッターインジェクションやアノテーションは？」
    - A. 単純にセッターインジェクションが嫌いです。あれは DI に巻き込むべきではないただのメソッドコールです
    - A. アノテーションは前時代的であり依存が大量に増えるので、やるとしたらアトリビュートでの対応になります
- Q.「メソッド名でキーワード縛りでもしてるの？」
    - A. 一番最初に `include` と `extends` を実装したら不思議とそうなってしまいました
- Q.「なんで非 static で factory 動作？」
    - A. 値 or クロージャに終止したかったからです。 static キーワードはただの流用です
- Q.「なんでカステラ？」
    - A. なんか [カステラ](https://www.google.com/search?q=castella&tbm=isch) って [コンテナ](https://www.google.com/search?q=container&tbm=isch) っぽくない？

## Lisence

MIT

## Release

### 1.0.0

- publish
