<?php

namespace MTLA;

use Closure;
use RuntimeException;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\ClawbackOperationBuilder;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\Memo;
use Soneso\StellarSDK\MuxedAccount;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\Responses\Account\AccountSignerResponse;
use Soneso\StellarSDK\SetOptionsOperationBuilder;
use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\TransactionBuilder;
use Soneso\StellarSDK\Xdr\XdrSignerKey;
use Soneso\StellarSDK\Xdr\XdrSignerKeyType;
use function Sodium\compare;

class CalcVoices
{
    private StellarSDK $Stellar;
    private string $main_account;
    private string $the_token;
    private Closure $logger;
    private bool $debug_mode = false;

    private AccountCollection $Accounts;
    private array $delegates_assembly = [];
    private array $delegates_council = [];

    public function __construct(StellarSDK $Stellar, string $main_account, string $the_token)
    {
        $this->Stellar = $Stellar;
        $this->main_account = $main_account;
        $this->the_token = $the_token;
        $this->Accounts = new AccountCollection();
    }

    //region Logging
    public function setLogger(Closure $logger): void
    {
        $this->logger = $logger;
    }

    public function setDefaultLogger(): void
    {
        $this->setLogger(function (bool $debug, string $string) {
            if (!$debug || $this->debug_mode) {
                print $string . "\n";
            }
        });
    }

    public function isDebugMode(bool $debug_mode = null): bool
    {
        if ($debug_mode !== null) {
            $this->debug_mode = $debug_mode;
        }

        return $this->debug_mode;
    }

    public function log(string $string = ''): void
    {
        ($this->logger)(true, $string);
    }

    public function print(string $string = ''): void
    {
        ($this->logger)(false, $string);
    }

    //endregion

    public function run(): void
    {
        if (!isset($this->logger)) {
            $this->setDefaultLogger();
        }

        $this->loadTokenHolders();

        $this->processCouncilDelegations();
        $council_candidates = $this->analiseCouncilDelegations();
        $this->updateCouncil($council_candidates);
    }

    private function loadTokenHolders(): void
    {
        $Accounts = $this->Stellar
            ->accounts()
            ->forAsset(
                Asset::createNonNativeAsset(
                    $this->the_token,
                    $this->main_account
                )
            )
            ->execute();
        $accounts = [];
        do {
            $this->log('Fetch accounts page.');
            foreach ($Accounts->getAccounts() as $Account) {
                $accounts[] = $Account;
            }
            $Accounts = $Accounts->getNextPage();
        } while ($Accounts->getAccounts()->count());

        $this->print('Открывшие линии доверия к MTLAP:');

        foreach ($accounts as $AccountResponse) {
            if ($AccountResponse instanceof AccountResponse) {
                $Account = $this->processStellarAccount($AccountResponse);

                $this->print(sprintf(
                    "%s\t%s\t%s\t%s",
                    $Account->getId(),
                    $Account->getTokenAmount(),
                    $this->delegates_assembly[$Account->getId()] ?? '',
                    $this->delegates_council[$Account->getId()] ?? ''
                ));
            }
        }

        $this->print();
    }

    private function getAmountOfTokens(AccountResponse $Account): int
    {
        foreach ($Account->getBalances()->toArray() as $Asset) {
            if (($Asset instanceof AccountBalanceResponse)
                && $Asset->getAssetCode() === $this->the_token
                && $Asset->getAssetIssuer() === $this->main_account
            ) {
                return (int)$Asset->getBalance();
            }
        }

        return 0;
    }

    private function getAssemblyDelegation(AccountResponse $Account): ?string
    {
        $Data = $Account->getData();
        $delegate = $Data->get('mtla_delegate') ?? $Data->get('mtla_a_delegate');
        if ($this->validateStellarAccountIdFormat($delegate)) {
            return $delegate;
        }

        return null;
    }

    private function getCouncilDelegation(AccountResponse $Account): ?string
    {
        $Data = $Account->getData();
        $delegate = $Data->get('mtla_delegate') ?? $Data->get('mtla_c_delegate');
        if ($this->validateStellarAccountIdFormat($delegate)) {
            return $delegate;
        }

        return null;
    }

    private function validateStellarAccountIdFormat(?string $account_id): bool
    {
        if (!$account_id) {
            return false;
        }

        return preg_match('/\AG[A-Z2-7]{55}\Z/', $account_id);
    }

    private function processCouncilDelegations(): void
    {
        $this->print("Проверка делегаций для Совета:");
        // Mock:
//        $this->delegates['GDLTH4KKMA4R2JGKA7XKI5DLHJBUT42D5RHVK6SS6YHZZLHVLCWJAYXI'] = 'GAKVQQD5HFSSXWN3E3K6QL573NQG5GJNFKW52RY6FPXH3CYVVADCDH4U';
//        $this->delegates['GAKVQQD5HFSSXWN3E3K6QL573NQG5GJNFKW52RY6FPXH3CYVVADCDH4U'] = 'GDLTH4KKMA4R2JGKA7XKI5DLHJBUT42D5RHVK6SS6YHZZLHVLCWJAYXI';

        $found_delegates = array_keys($this->delegates_council);

        $Processed = new AccountCollection();

        while ($delegator_id = array_shift($found_delegates)) {
            $this->log("Начало для " . $delegator_id);
            $Delegator = $this->Accounts->getById($delegator_id);
            if (!$Delegator) {
                throw new RuntimeException('Unknown account_id (not from the preloaded list: ' . $delegator_id . ').');
            }

            $Chain = new AccountCollection();
            $Chain->addItem($Delegator);

            do {
                $target_id = $this->delegates_council[$Delegator->getId()];
                $this->log("Делегирует " . $target_id);
                $Target = $this->Accounts->getById($target_id);
                if (!$Target) {
                    $this->log("Подгружаем новый аккаунт " . $target_id);
                    if (!($Target = $this->loadNewAccount($target_id))) {
                        $this->log("Подгрузка неудачна");
                        continue 2;
                    }
                }

                $Delegator->setDelegateCouncilTo($Target);
                $Processed->addItem($Delegator);

                if ($Chain->isExists($Target)) {
                    $Delegator->isBrokenCouncilDelegate(true);
                    $this->print('ENDLES LOOP!');
                    continue 2;
                }
                $Chain->addItem($Target);

                if (array_key_exists($Target->getId(), $this->delegates_council) && !$Processed->isExists($Target)) {
                    $Delegator = $Target;
                } else {
                    $Delegator = null;
                }
            } while ($Delegator);
        }

        $this->print();
    }

    private function loadNewAccount(string $id): ?Account
    {
        try {
            $AccountResponse = $this->Stellar->requestAccount($id);
            return $this->processStellarAccount($AccountResponse);
        } catch (HorizonRequestException) {
            return null;
        }
    }

    private function processStellarAccount(AccountResponse $AccountResponse): Account
    {
        $account_id = $AccountResponse->getAccountId();
        $token_count = $this->getAmountOfTokens($AccountResponse);
        $Account = new Account($account_id, $token_count);
        $this->Accounts->addItem($Account);
        if ($delegate = $this->getAssemblyDelegation($AccountResponse)) {
            $this->delegates_assembly[$account_id] = $delegate;
        }
        if ($delegate = $this->getCouncilDelegation($AccountResponse)) {
            $this->delegates_council[$account_id] = $delegate;
        }

        return $Account;
    }

    private function analiseCouncilDelegations(): array
    {
        $ListBrokenDelegates = new AccountCollection();
        $ListNoDelegates = new AccountCollection();
        foreach ($this->Accounts->asArray() as $Account) {
            if ($Account->isBrokenCouncilDelegate()) {
                $ListBrokenDelegates->addItem($Account);
                continue;
            }

            if (!$Account->getDelegateCouncilTo()) {
                $ListNoDelegates->addItem($Account);
            }
        }

        if ($ListBrokenDelegates->isNonEmpty()) {
            $this->print('Сломаные делегации:');
            foreach ($ListBrokenDelegates->asArray() as $Account) {
                $token_power = $Account->getTokenPower();
                $this->print(sprintf("%s\t%s", $Account->getId(), $token_power));
                if (!$token_power) {
                    continue;
                }
                $tree = $Account->getCouncilTree();
                $this->printDelegationTree($tree['delegated'], 1);
//                $this->print(json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
            $this->print('');
        }

        $council_candidates = [];

        if ($ListNoDelegates->isNonEmpty()) {
            $this->print('Без делегации:');
            foreach ($ListNoDelegates->asArray() as $Account) {
                $token_power = $Account->getTokenPower();
                if ($token_power) {
                    $council_candidates[$Account->getId()] = $token_power;
                }
                $this->print(sprintf("%s\t%s", $Account->getId(), $token_power));
                if (!$Account->getDelegatedTokenAmount()) {
                    continue;
                }
                $tree = $Account->getCouncilTree();

                $this->printDelegationTree($tree['delegated'], 1);
//                $this->print(json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
            $this->print();
        }

        return $council_candidates;
    }

    private function printDelegationTree($items, $level = 0): void
    {
        foreach ($items as $item) {
            $this->print(
                str_repeat("\t", $level)
                . $item['id']
                . "\t"
                . ($item['own_token_amount'] + $item['delegated_token_amount'])
            );
            $this->printDelegationTree($item['delegated'], $level + 1);
        }
    }

    private function updateCouncil(array $council_candidates): void
    {
        $new_arr = [];
        foreach ($council_candidates as $address => $token_power) {
            $new_arr[$address] = [
                'address' => $address,
                'token_power' => $token_power,
            ];
        }
        $council_candidates = $new_arr;
        uasort($council_candidates, function (array $a, array $b) {
            if ($a['token_power'] > $b['token_power']) {
                return -1;
            }

            if ($a['token_power'] < $b['token_power']) {
                return 1;
            }

            return strcmp($a['address'], $b['address']);
        });

        $top = array_slice(array_keys($council_candidates), 0, 20);

        $this->print('Ожидаемый состав Совета');

        $voices_sum = 0;
        $calculated_weights = [];
        foreach ($top as $account_id) {
            $sign_weight = $this->calcCouncilMemberVoiceByTokens($council_candidates[$account_id]['token_power']);
            $this->print(sprintf(
                "%s\t%s\t%s",
                $account_id,
                $council_candidates[$account_id]['token_power'],
                $sign_weight,
            ));
            $calculated_weights[$account_id] = $sign_weight;
            $voices_sum += $sign_weight;
        }

        $current_signs = [];
        try {
            $StellarAccount = $this->Stellar->requestAccount($this->main_account);
        } catch (HorizonRequestException) {
            throw new RuntimeException('Жопа');
        }
        /** @var AccountSignerResponse $Signer */
        foreach ($StellarAccount->getSigners() as $Signer) {
            if ($Signer->getKey() === $this->main_account) {
                continue;
            }
            $current_signs[$Signer->getKey()] = $Signer->getWeight();
        }
        $this->print();


        $this->print('Текущий состав Совета:');
        foreach ($current_signs as $account_id => $weight) {
            $this->print(sprintf("%s\t%s", $account_id, $weight));
        }
        $this->print();

        // Смотрим что там сейчас
        $this->print('Разница расчетного и текущего:');
        $changes = [];
        // Удаляемые
        foreach ($current_signs as $address => $weight) {
            if (!in_array($address, $top)) {
                $changes[$address] = 0;
            }
        }
        // Добавляемые и изменённые
        foreach ($top as $address) {
            $voice = $calculated_weights[$address];
            if (!array_key_exists($address, $current_signs) || $current_signs[$address] !== $voice) {
                $changes[$address] = $voice;
            }
        }
        foreach ($changes as $address => $weight) {
            $diff = 'ошибка1';
            $old = $current_signs[$address] ?? 0;
            if ($weight === $old) {
                $diff = 'ошибка2';
            } else if ($old === 0) {
                $diff = 'новый';
            } else if ($weight < $old) {
                $diff = '−' . $old - $weight;
            } else if ($weight > $old) {
                $diff = '+' . $weight - $old;
            }
            $this->print(sprintf("\t%s\t%s\t%s", $address, $weight, $diff));
        }
        if (!$changes) {
            $this->print("\tНет разницы");
        }

        $this->print();

        $this->print("Всего голосов: " . $voices_sum);
        $for_transaction = floor($voices_sum / 2 + 1);
        $this->print("Нужно для мультиподписи транзы: " . $for_transaction);
        $this->print();

        $Transaction = new TransactionBuilder($StellarAccount);
        $Transaction->addMemo(Memo::text('Update sign weights'));
        $Transaction->setMaxOperationFee(100000);
        $op_count = 0;
        $last_item = array_key_last($changes);
        foreach ($changes as $address => $voice) {
            $Signer = new XdrSignerKey();
            $Signer->setType(new XdrSignerKeyType(XdrSignerKeyType::ED25519));
            $Signer->setEd25519(KeyPair::fromAccountId($address)->getPublicKey());
            $Operation = new SetOptionsOperationBuilder();
            $Operation->setSigner($Signer, $voice);
            if ($address === $last_item) {
                $Operation->setMasterKeyWeight(0);
                $Operation->setLowThreshold($for_transaction);
                $Operation->setMediumThreshold($for_transaction);
                $Operation->setHighThreshold($for_transaction);
            }
            $Transaction->addOperation($Operation->build());
            $op_count++;
        }

        if ($op_count) {
            $this->print($Transaction->build()->toEnvelopeXdrBase64());
        } else {
            $this->print('Нечего изменять.');
        }
    }

    public function calcCouncilMemberVoiceByTokens(int $token_power): mixed
    {
        return (int) floor(log(max($token_power, 2) - 1, 10) + 1);
    }
}