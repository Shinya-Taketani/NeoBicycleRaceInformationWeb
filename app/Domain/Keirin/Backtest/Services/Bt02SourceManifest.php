<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\Bt02SourceManifestEntryDto;
use LogicException;

class Bt02SourceManifest
{
    public const VERSION = 'BT02-STAT-SOURCE-MANIFEST-v1';

    public const HASH = '92aa8439775101c4f9d190d829b8a0f3e3702fd8646101b66a42b68babb79e6d';

    public const SOURCE_FINGERPRINT_VERSION = 'BT02-SOURCE-FINGERPRINT-v1';

    public const CONTENT_FINGERPRINT_VERSION = 'BT02-SOURCE-CONTENT-FINGERPRINT-v1-PG18.4';

    private const YEAR = [
        2022 => [25, '82a88496-35b4-48fc-81c3-8b46b5eb626f', 24868, 174152, '2021-01-01'],
        2023 => [26, '71c344f6-e09b-4496-9cd0-a68642e2c462', 25561, 181548, '2022-01-01'],
        2024 => [1, '07f2fc31-0d9c-41d9-95b7-80c7afb396ce', 25624, 182004, '2023-01-01'],
        2025 => [27, 'b62ba626-5019-4018-8cd7-7d09c61a8ceb', 25273, 180005, '2024-01-01'],
    ];

    /** @var array<int, array<string, array{int, string}>> */
    private const RUNS = [
        2022 => [
            'STAT-07' => [36, '29807906-e918-44bf-a910-d010cc9709bd'], 'STAT-08' => [37, 'e8ec9bb5-b7a2-42ad-a24e-9deb2d0cb093'],
            'STAT-10' => [31, 'aa426681-edd8-4ed3-a434-7cf2097308d1'], 'STAT-11' => [32, '3d4150cd-8061-424d-a44c-c9a3c409d09f'],
            'STAT-12' => [33, 'bcccb695-9f1e-4f1a-81d2-4336d53713c0'], 'STAT-23' => [38, '7ab52f27-5080-4843-baaf-a2dfd26f667a'],
            'STAT-24' => [34, '60bb6eae-7a53-41fb-a25d-1c82d5fee49b'], 'STAT-26' => [35, '7e8f3287-d280-4f85-9395-96c192da7bb7'],
            'STAT-31' => [39, '91fbb7dc-6662-4da5-b3e8-d3e8b6b214c6'], 'STAT-32' => [40, '28680e8b-9cb6-4ee4-a0b1-b6ea1e398973'],
            'STAT-33' => [41, 'c443b669-22a7-44cb-98b7-f1722a18b246'], 'STAT-39' => [29, '8c926215-c236-4f33-8421-c8e38f54ac16'],
            'STAT-41' => [28, '39a54292-16ef-40c9-bc89-0b7d67657617'], 'STAT-42' => [30, '16a5ea49-3642-4133-a741-c9b7b2bb8aab'],
        ],
        2023 => [
            'STAT-07' => [50, '0265a359-9cb6-44cf-9e76-0684b11c0bbf'], 'STAT-08' => [51, '528d0c0c-f9ba-4162-a302-15f56f53b837'],
            'STAT-10' => [45, '11831435-44ae-464e-9be5-0ca95d62ae40'], 'STAT-11' => [46, '129b139e-2dc2-440f-a30f-279a086050d2'],
            'STAT-12' => [47, '7187ab1e-1410-408b-8379-c69b44b3d5e0'], 'STAT-23' => [52, 'f272cfe4-ab52-4104-9492-47267d1956ed'],
            'STAT-24' => [48, '148b9983-9674-48e3-9875-7aba5c78c08d'], 'STAT-26' => [49, '67b9b9ab-8e92-47ee-b2e8-32b295a20128'],
            'STAT-31' => [53, '5b6a0cce-2f7f-44d1-8090-f825ab417be5'], 'STAT-32' => [54, '33927323-e49c-45f6-a73b-f98d62523850'],
            'STAT-33' => [55, 'afc7f1e5-eb52-4d0d-b839-be0a4ef02f0e'], 'STAT-39' => [43, 'd31ab089-31ca-407a-ba11-a5073ef2dd30'],
            'STAT-41' => [42, '422057d9-d3ab-4da8-90de-81acb535a291'], 'STAT-42' => [44, '6fd5d9ef-e9b7-45b1-b607-ac01236e6de7'],
        ],
        2024 => [
            'STAT-07' => [13, '5d39d2be-39a6-4d22-ae8c-b02d59ac235c'], 'STAT-08' => [14, '36ee7b2f-83c8-4e91-8d76-82c444dcb02e'],
            'STAT-10' => [2, '8ffd0666-d9ba-46ae-a707-884abecf75c0'], 'STAT-11' => [3, '42b58042-528b-4855-beb1-73c3de72260c'],
            'STAT-12' => [4, 'b549f9fa-6273-4f6b-b6db-f22adeffd0db'], 'STAT-23' => [15, 'c8f9bc2a-4b20-4e27-9c70-33f9f0064df8'],
            'STAT-24' => [5, '210070d2-ec2b-47d0-95e6-b70710cebd55'], 'STAT-26' => [6, '6dc93215-96ba-4ded-a93b-9c7926a89296'],
            'STAT-31' => [16, '253147b9-cda3-4371-aa30-7d313efe361e'], 'STAT-32' => [17, '080490ba-fe80-49e0-bf6d-883aa65ce185'],
            'STAT-33' => [18, 'cc3c7e13-6f43-4a41-8817-ccb42d3368ad'], 'STAT-39' => [21, '91ba79e3-8524-4711-8756-2012ebe2def2'],
            'STAT-41' => [24, '307618b5-8acd-408c-8e17-d7849be7ce5c'], 'STAT-42' => [22, '7546165e-c153-466c-a518-cfd2244fe32f'],
        ],
        2025 => [
            'STAT-07' => [64, '5aa42be8-2d1a-4506-bb3b-9b1255eeab55'], 'STAT-08' => [65, '07d211c7-16a6-4041-97b9-18b643f161f2'],
            'STAT-10' => [59, 'c5003052-e691-4819-bbf8-abaff62b15bb'], 'STAT-11' => [60, 'af5bfa0a-c09d-461e-9c64-844d913bad6d'],
            'STAT-12' => [61, 'dbab8a09-4500-42e5-b575-b2b48c3c04aa'], 'STAT-23' => [66, '30dff1ee-14d3-41b5-8fb3-9ee083535f85'],
            'STAT-24' => [62, '04c36874-7790-43b4-8ceb-4a4ca9f1f554'], 'STAT-26' => [63, 'e5d53eaf-e652-4fe2-9489-a21c1c9fc31e'],
            'STAT-31' => [67, 'cc11220d-04f7-4dff-80b2-e21fd217c1a6'], 'STAT-32' => [68, '1a270772-52ce-4e51-867f-a46da69e818f'],
            'STAT-33' => [69, '43f02df1-27d7-49fa-a42c-f616e189fa43'], 'STAT-39' => [57, '7880b606-5b97-4820-ad01-a9c04ce110ce'],
            'STAT-41' => [56, 'aa365a83-e904-4e12-aceb-13d4cf2d7c1d'], 'STAT-42' => [58, '690c31fb-76c7-4830-85bf-644bc144eaf8'],
        ],
    ];

    /** @var array<int, array{string, string}> */
    private const FINGERPRINTS = [
        2 => ['751b393da5f64f6cc0280e2ab3ef506c9c1e33064025e23442e348e2df1b474d', 'd2f01868c29319864f6b60b4ee1bd2967b2bfac2541762e616c93a5ccd37cb09'],
        3 => ['93300d89048bc3c2a824e105f38af2a741275c9903be56fbc29812b0ac28b6f3', 'b11c1b7980bef291755927ce969abe1325610f8e3eb40cc33351740a1a377d24'],
        4 => ['c9ef2e3dec2694969e77c497d2e7896785cf3133bc1d46d4aa9124e438354c70', 'a19b0ec0b16c6f4db6798fdd82784f188545cdfeadd192a715cde215a25d7aec'],
        5 => ['8791a4ea2be754359ee0e8a356f78d81de9032c806192f9fedb050378f675501', '9fd3a33b4aff4c2c5a4050a3ce473ad13cc996ff6ad1299eaebc5b389d8e5c81'],
        6 => ['2764787b28da04b5017eb1fca678a2e53e6ac91a46c08907ae9204907b61bb31', 'bb1bde1eac9e80756483018806640302f133b9f21607948c3dceaf745be5cc11'],
        13 => ['28b435dafe9ee413405a9d8f3b2996e015adbd23f2e3005da96fa94f93b2e6dc', 'b5367fc77f8514e259eb68e12be350b2d1a01820c7ca2bb628f56170ad2bc03d'],
        14 => ['d092f5e40b81c9060f0e49240d0c1ef29654a11c673a930c6bba1da96d0bf923', 'eb7b4de9db1714dcb1628b7b13ec03dbb5c570df5d9244fa5c6b8a8d50fbe569'],
        15 => ['87c7b0722c4df15b9d1ae52c65e486ce37860667c7a447c0bddfc58091db4aee', 'b68b96348fb455dd3b1507bc417ba90e5e6bf300cb31868b710dbb21753623f9'],
        16 => ['a1159be0905034adf79c346ea3d924764859d669b4397fd1fd4c8bba2eb7dd90', '4378ba9808b5a6f9953d99b2319fe6954a7dce46878417add4bd505d46a2d00a'],
        17 => ['37d32d7adcaa5f0b8b22fbae46568a11f16c6c3ab736cdf951431e34a65c94ff', 'f21350783001a629f7530821d3751a9cbbdfa2f4329e5eab43bdebd5c1d736ee'],
        18 => ['3fea8fa5011229b5fe078e9591b739498b92e24cf1de1fbc1d4fce7e1fc251c1', 'cda002079b22ce74ed475064accb11b48aa0961e4e10114cf8fb23cf1c9e41a3'],
        21 => ['1b9a5af6806bb7833c9ab4c805a9bebe99f3b77112d203c3c37da1cd59e7d056', '43828dc9dfa26cdff01398022bc27eef7d8147a0a57220484885d216dff6b97c'],
        22 => ['470591448cf8a5eb1b061567ce592e7bbd3750b783ac8a3bc3840b6e39ba1431', '0c27d07219bb13321002a776e0fb25f1d284706f110def10b1b161931d5214c2'],
        24 => ['11dce9207f8f35b0d728e3bf0b6c1aa27b597c23bd3a07228c3f750db8ff27b8', 'db247cb1790c0938230327f5c39c2dc939fde344047fd6705247b07132e8d059'],
        28 => ['f96d169fbeafb1128542a0bd25ec6d7e48307ee79c6da87695b336439608dc47', '344ccbde818b406363e2142559d084bbfbdf89b9c311d648016b7845aad9bbdb'],
        29 => ['9fa432e0ef18317d5618c8ce7ea0678da714c3a402dcd9d41cae3be00d0b1e0f', '312b2014684272ab75b53f6f6e214632167d9d726c25519d1bbb8597551724c6'],
        30 => ['43b331a50f4bed4cf8cd5fb72ba4b3efa1c10592b70f52946f46ea3447133410', '3dc0611e200344c5beb816f35d3d68badb5638cd5fc0b4c020a589d4ed15f649'],
        31 => ['52cf73d8c353c02ccef47927e92d008bdd4b7d12a8d84f8b4b2e79342f773329', '698566465e6edfba913ae6af473cf66e6cfb687674027abfab5ba05aad178b24'],
        32 => ['a87199279bf9e043bb61cc3205f5799e1cdb156683b5a36cb1a3ea20727b1c9f', 'f40d186576c45381536959fc66a07b3b06fd7d0b975472bf0de5a8ed007cea47'],
        33 => ['5e2d211d0b9b4000cf3f7fa14f0f34c19cbd22a2c7e3cadbc046424f92c11c60', 'bf22e0c5e641e7daf6a5c0e5350c898eaf60ee7834d0f65cedcd2f2d3d614ff5'],
        34 => ['f713b46e2e4bf1138c0432be694febe14cdbbf69bef6c5f86c92bb1488ae9a46', '33e83814f3379d9e24bc659f00214fda45bb46477512427367d9b524d6bfd759'],
        35 => ['5f81cbba733b55788f75ce85050a86415e84bf0e9f62d80fa2b2a219df67247a', 'aab459510112faf9710a88917fe5fa84bb3735b0b402c55c476120f3a7f3c6f1'],
        36 => ['58770769ac216e273f108d7785ddb834d148f4b3bb63e332db90ad44fca8fe3a', '7ce476ee72ba40e4d49b7865b6e3ca1ddf0da78e3a8ad483ea0b9dc71a0b3086'],
        37 => ['ba869a9f67b89a1f38a9755e7340590351991c15829c00975cde139bcecd6512', '32fa0fbfb05363f6f5339904e07329f9e900c02cc64d2836471de3b39292c6d0'],
        38 => ['fa6b81227a4eb95ab1dbd4888d9b0a45755662f1f33f4e52fa792ef78be1bc89', '601ca05f0004a9ddab4da6cf04e443f7dfc54d08d6832620c8acee68dd165f54'],
        39 => ['9a57bc27bcb13ac294565965fa11cb8ac539a6a6c36c0311f730e67a68c75b61', 'a51ef3ec680690cf6b26315f714fe121e7b4d43a7cfe0caeb52fe97e1cd697b8'],
        40 => ['f885170d939f0955389b91a3a3ec3b6265239d7295457210ce610489209595ea', '47e50d45b5df7a8b83d76a24c7e26d754811c2729c2679ba3931b3be0d77d2c7'],
        41 => ['5805c752056b5996edff73e75f6540406a8dea10c06e214c76e566c0993567e3', '25711590dcde746c046341b9bc7e169d8f1ba29822a64d81e61f431fe63ffbb7'],
        42 => ['10bbfd6522945c612204c14ea9a155aef1ad427ae000113b54d92ed63181789e', '443e2b3fd94d92854e54c7243b96507f2232bcb0d6901fbcae6939e251e3bbce'],
        43 => ['bbd597796577c6532fc07020cdd53c6104cc92375be0efd890b779f5fe058111', '30f5917873eb31aa63739649749a3c0f7b3173cfd64963663002ac1862065cbc'],
        44 => ['e6d0e6247436d8bdc2a788e35d2c1f9940dff54b66c70d17f37fa50a93a606c3', '122532afa2647e6df2a5aabd2895236256e58dc7ac54470b608f50733a38e083'],
        45 => ['ac3318cce2b5f31e0f607a29a7ccd7b856c86e2bb67d1417124da477be339ef4', '9dbe46cbd0db8d524a16923da82ca9d8c7524e8294f6852f2ea7a06a4f956356'],
        46 => ['9fd45b54e5fe493ccf289d1b8d79bd189177a25a15ee65d204d50b352ef556d5', 'cb394c65333b4937726226fb7926a8c9abccf3f06b5a99140c95099762bdc721'],
        47 => ['3324f34d81de5df86edc2c40d0b452cbabcc71c1eff7aa9553c27b01430fe5a2', 'ef43513e51790dc1d7763a76d55bc5a1b89e4d239b81e50f41acefcf95db0f32'],
        48 => ['4de7908bac1dc40c438189a59c8f5d31d8e6b45bbfcf4589ad9f68b26f9af010', '393c007fb24f608cc8e596b2a49a639e8a1c247ac8dc376f374a14632c57d682'],
        49 => ['ad5384d0708fe9676a4794c114c12685dbf33abdc441edd7b7c119c29db7f115', '6bde17f9422febd5f9992e94e54b6ac3ac90e3b2858ac5e98c6a47dceb9b69b9'],
        50 => ['f99bde1011d89268d908b731845607638ea6c4479392aca9233f1264baecfe79', 'd52651c3c12c7ecafd9d919c56b513868cc02c4b59073419e25f2cc84ed50b8f'],
        51 => ['5ad91ffe93f511a7626d967dac047f39a2d4308d474ef6e75af455d7efa6c9c0', 'fa08fd538bb4f58534e80257bf24d876619ce8cc44dd7dd230c23a25b69991c8'],
        52 => ['70206628f44fc47877339e42e2d3599f8f4073765d732c4da38fc7db7bc8e82d', '7a95551821b6ced4d8a1e161a169def201adff7816799ef42317d1b9bc2fe8d5'],
        53 => ['63526e09835deca7130decbf8aec586965aa75c00bdba144340d1f904a053dde', '467017cceb83ccacfcfe3e9fa3139ebc570bf75020f79b7c563c01c3b4b0df5c'],
        54 => ['e719689821e8eb8ed5d28f751edf926c1d77633d91900716786fbecff8ec0b8a', 'cfae3fd33aeaecf2026598f4ddfdcf6ddb074cec605bcbf39b14dd2ce69ba17c'],
        55 => ['1bfa83704702b9dc74f213fe9e49ae331c61d84f62150a7a4c5bd20c41ba3d6a', 'c515bbb68041c3ddcc417ddd75a588b471a05032b97346b991c8af6dd41a86c7'],
        56 => ['54a358ab70b89f75c195e35a5244937643462ed9d2579ee7d0c8c127a60570e1', '724669a84b46f2969443ca1ae3bd9abe20c0410a8c44610073ba9be760987bdd'],
        57 => ['d7b697d56d4da54c6b2f06eb765b762313e0329ea7a17b64d7d10396eb99a371', '2aafd93fd4a05845f22c0a6710467c04ddcd85225259ba5a6ee4a6e788b0d03b'],
        58 => ['e21cc1b718d653a7decd286dd9df95955361c413a908ac446ed13152bc881a70', 'c74860b312d12574e1b8981d5f6e8acacbff0d0f211a7c8086b93214dc406dfb'],
        59 => ['d4ad11c13d90be980dfe7872aab0ffc41c4d298cd5a5ddbc3c925972ecb9b18f', 'fd21a73e18f95b69972c05c16bdd0fcaded843c50d868391f084e8f98eb40686'],
        60 => ['ca801c6946173a7ec3c0a41a641442c941845277a99cacb6b981327a98e3fe8c', '104056ce9683035d8ce65c68374eea02ec4345ca26b5edaedc0ceded5246f30a'],
        61 => ['dfbfcffa0bf2ebecdcb1eb13bef6786315c82e9a927a3f08dbcb23e4a36ab264', '82414c1baa1a7be944231d29a00f7b709e65909d1a268755642d4671fb6086ed'],
        62 => ['2049965469d641594663ba430ce56457fcc8bfadfcc98a4e832d9d3e8d87195f', 'd877b3ad48c4f42582af615957f335c9a0b85b0741ab9363a80fd76bb261a499'],
        63 => ['4a37559dadaa2bed0dbc44256bb969ff881fe5c79090a6ced03a8318e0a6776b', 'd0ed0a6fa79738c15a8f5920445a79d82e9b89d39d1ac0e0698f79a7350ad537'],
        64 => ['7e8fbe38721a8efe41cf90a9bff4e7269b8dd1683018fb11492e0171a170aeb2', '001996aec9c9f31e111b5e92b7e4f9d16f2e89ad6ac34a97ca32e6ab0479cabd'],
        65 => ['a0e2a863e6db30d788560346f86656c37a9adaaf77b6a532b5129995590b4c51', '45ad45ebb0ef25d192e97214d4e670bb765925d73627852762fbebbb100d6c50'],
        66 => ['4cc9cf641a3e5630b13f915543f781b8fafb430c9904d2ba4333a64d68ed5f93', 'c7bab086771aa656137f2b2f9d3f7561073abda495c9e929ce9aca4ea95e05fb'],
        67 => ['72dc2f0e1ef2b7a906e2230f106845c2d93dfc0e700b78efcb89b38cd63f89f7', 'be03be417e139c28e4cec96f28107e1e7e869e4dc1ebe0be1fb0dac15c97661e'],
        68 => ['041bcec4d742892e4fbeaa273df01ab9e530a7dae69fbcd644dc0e5c439226b5', '5b3d1db650bfdb5a95206c6b5940187d70e1cb5270d62d463479c49a8c7f6bba'],
        69 => ['8152190e3ae7df7a45d460fc2b96e41c4196b45633978dc68a212191fe5c9bef', 'b549ac33e09ce58148efbee80101add6067c53a72a8c3f550089a7e9ee761d17'],
    ];

    /** @return list<Bt02SourceManifestEntryDto> */
    public function entries(): array
    {
        $entries = [];
        foreach (self::RUNS as $year => $runs) {
            [$sourceId, $sourceUuid, $races, $entriesCount, $historyFrom] = self::YEAR[$year];
            foreach ($runs as $statCode => [$runId, $runUuid]) {
                [$sourceHash, $contentHash] = self::FINGERPRINTS[$runId] ?? throw new LogicException("BT-02 fingerprints missing for run {$runId}.");
                $raceSubject = $statCode === 'STAT-41';
                $entries[] = new Bt02SourceManifestEntryDto(
                    $year,
                    $statCode,
                    $runId,
                    $runUuid,
                    "{$statCode}-existing-db-v1",
                    $sourceId,
                    $sourceUuid,
                    "{$year}-01-01",
                    "{$year}-12-31",
                    $raceSubject ? null : $historyFrom,
                    $raceSubject ? 'RACE' : 'RACE_ENTRY',
                    $races,
                    $entriesCount,
                    $raceSubject ? $races : $entriesCount,
                    $sourceHash,
                    $contentHash,
                );
            }
        }

        return $entries;
    }

    public function hash(): string
    {
        return self::HASH;
    }

    public function computedHash(): string
    {
        $serialized = '';
        foreach ($this->entries() as $entry) {
            $serialized .= implode(',', array_map(
                fn (int|string|null $value): string => $value === null ? '\\N' : (string) $value,
                array_values($entry->canonical()),
            ))."\n";
        }

        return hash('sha256', $serialized);
    }

    public function for(int $year, string $statCode): Bt02SourceManifestEntryDto
    {
        foreach ($this->entries() as $entry) {
            if ($entry->year === $year && $entry->statCode === $statCode) {
                return $entry;
            }
        }

        throw new LogicException("No fixed BT-02 source exists for {$year} {$statCode}.");
    }
}
