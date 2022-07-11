<?php

declare(strict_types=1);

namespace In2code\Femanager\Utility;

use In2code\Femanager\Domain\Model\User;
use In2code\Femanager\Domain\Model\UserGroup;
use In2code\Femanager\Domain\Repository\UserRepository;
use TYPO3\CMS\Core\Crypto\PasswordHashing\Argon2iPasswordHash;
use TYPO3\CMS\Core\Crypto\PasswordHashing\BcryptPasswordHash;
use TYPO3\CMS\Core\Crypto\PasswordHashing\BlowfishPasswordHash;
use TYPO3\CMS\Core\Crypto\PasswordHashing\Md5PasswordHash;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Crypto\PasswordHashing\Pbkdf2PasswordHash;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PhpassPasswordHash;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Object\ObjectManager;

/**
 * Class UserUtility
 * @codeCoverageIgnore
 */
class UserUtility extends AbstractUtility
{

    /**
     * Return current logged in fe_user
     *
     * @return User|null
     */
    public static function getCurrentUser()
    {
        if (self::getPropertyFromUser() !== null) {
            /** @var UserRepository $userRepository */
            $userRepository = GeneralUtility::makeInstance(ObjectManager::class)->get(UserRepository::class);

            return $userRepository->findByUid((int)self::getPropertyFromUser());
        }

        return null;
    }

    /**
     * Get property from current logged in Frontend User
     *
     * @param string $propertyName
     * @return string|null
     */
    public static function getPropertyFromUser($propertyName = 'uid')
    {
        if (!empty(self::getTypoScriptFrontendController()->fe_user->user[$propertyName])) {
            return self::getTypoScriptFrontendController()->fe_user->user[$propertyName];
        }

        return null;
    }

    /**
     * Get Usergroups from current logged in user
     *
     *  array(
     *      1,
     *      5,
     *      7
     *  )
     *
     * @return array
     */
    public static function getCurrentUsergroupUids()
    {
        $currentLoggedInUser = self::getCurrentUser();
        $usergroupUids = [];
        if ($currentLoggedInUser !== null) {
            foreach ($currentLoggedInUser->getUsergroup() as $usergroup) {
                $usergroupUids[] = $usergroup->getUid();
            }
        }

        return $usergroupUids;
    }

    /**
     * Autogenerate username and password if it's empty
     *
     * @param User $user
     * @return User $user
     */
    public static function fallbackUsernameAndPassword(User $user)
    {
        $settings = self::getConfigurationManager()->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
            'Femanager',
            'Pi1'
        );
        $autogenerateSettings = $settings['new']['misc']['autogenerate'];
        if (!$user->getUsername()) {
            $user->setUsername(
                StringUtility::getRandomString(
                    $autogenerateSettings['username']['length'],
                    $autogenerateSettings['username']['addUpperCase'],
                    $autogenerateSettings['username']['addSpecialCharacters']
                )
            );
            if ($user->getEmail()) {
                $user->setUsername($user->getEmail());
            }
        }
        if (!$user->getPassword()) {
            $password = StringUtility::getRandomString(
                $autogenerateSettings['password']['length'],
                $autogenerateSettings['password']['addUpperCase'],
                $autogenerateSettings['password']['addSpecialCharacters']
            );
            $user->setPassword($password);
            $user->setPasswordAutoGenerated($password);
        }

        return $user;
    }

    /**
     * @param User $user
     * @param array $settings
     * @return User
     */
    public static function takeEmailAsUsername(User $user, array $settings)
    {
        if ($settings['new']['fillEmailWithUsername'] === '1') {
            $user->setEmail($user->getUsername());
        }

        return $user;
    }

    /**
     * Overwrite usergroups from user by flexform settings
     *
     * @param User $user
     * @param array $settings
     * @param string $controllerName
     * @return User $object
     */
    public static function overrideUserGroup(User $user, $settings, $controllerName = 'new')
    {
        if (!empty($settings[$controllerName]['overrideUserGroup'])) {
            $user->removeAllUsergroups();
            $usergroupUids = GeneralUtility::trimExplode(',', $settings[$controllerName]['overrideUserGroup'], true);
            foreach ($usergroupUids as $usergroupUid) {
                /** @var UserGroup $usergroup */
                $usergroup = self::getUserGroupRepository()->findByUid($usergroupUid);
                $user->addUsergroup($usergroup);
            }
        }

        return $user;
    }

    /**
     * Convert password to Argon2i, Bcrypt, Pbkdf2, Phpass, Blowfish or Md5 hash
     *
     * @param User $user
     * @param string $method
     * @throws \TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException
     */
    public static function convertPassword(User $user, $method)
    {
        if (array_key_exists('password', UserUtility::getDirtyPropertiesFromUser($user))) {
            self::hashPassword($user, $method);
        }
    }

    /**
     * Hash a password from $user->getPassword()
     *
     * @param User $user
     * @param string $method "Argon2i", "Bcrypt", "Pbkdf2", "Phpass", "Blowfish", "md5" or "none" ("sha1" for TYPO3 V8)
     * @throws \TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException
     */
    public static function hashPassword(User &$user, $method)
    {
        $hashInstance = false;
        $saltedHashPassword = $user->getPassword();
        /** @var PasswordHashFactory $passwordHashFactory */
        $passwordHashFactory = GeneralUtility::makeInstance(PasswordHashFactory::class);
        switch ($method) {
            case 'Argon2i':
                $hashInstance = GeneralUtility::makeInstance(Argon2iPasswordHash::class);
                break;

            case 'Bcrypt':
                $hashInstance = GeneralUtility::makeInstance(BcryptPasswordHash::class);
                break;

            case 'Pbkdf2':
                $hashInstance = GeneralUtility::makeInstance(Pbkdf2PasswordHash::class);
                break;

            case 'Phpass':
                $hashInstance = GeneralUtility::makeInstance(PhpassPasswordHash::class);
                break;

            case 'Blowfish':
                $hashInstance = GeneralUtility::makeInstance(BlowfishPasswordHash::class);
                break;

            case 'md5':
                $hashInstance = GeneralUtility::makeInstance(Md5PasswordHash::class);
                break;
            case 'none':
                break;

            default:
                $hashInstance = $passwordHashFactory->getDefaultHashInstance(TYPO3_MODE);
        }

        if ($hashInstance === false) {
            $user->setPassword($saltedHashPassword);
        } else {
            $user->setPassword($hashInstance->getHashedPassword($saltedHashPassword));
        }
    }

    /**
     * Get changed properties (compare two objects with same getter methods)
     *
     * @param User $changedObject
     * @return array
     *            [firstName][old] = Alex
     *            [firstName][new] = Alexander
     */
    public static function getDirtyPropertiesFromUser(User $changedObject)
    {
        $dirtyProperties = [];
        $ignoreProperties = [
            'txFemanagerChangerequest',
            'ignoreDirty',
            'isOnline',
            'lastlogin'
        ];

        foreach ($changedObject->_getCleanProperties() as $propertyName => $oldPropertyValue) {
            if (method_exists($changedObject, 'get' . ucfirst($propertyName))
                && !in_array($propertyName, $ignoreProperties)
            ) {
                $newPropertyValue = $changedObject->{'get' . ucfirst($propertyName)}();
                if (!is_object($oldPropertyValue) || !is_object($newPropertyValue)) {
                    if ($oldPropertyValue !== $newPropertyValue) {
                        $dirtyProperties[$propertyName]['old'] = $oldPropertyValue;
                        $dirtyProperties[$propertyName]['new'] = $newPropertyValue;
                    }
                } else {
                    if (get_class($oldPropertyValue) === 'DateTime') {
                        /** @var $oldPropertyValue \DateTime */
                        /** @var $newPropertyValue \DateTime */
                        if ($oldPropertyValue->getTimestamp() !== $newPropertyValue->getTimestamp()) {
                            $dirtyProperties[$propertyName]['old'] = $oldPropertyValue->getTimestamp();
                            $dirtyProperties[$propertyName]['new'] = $newPropertyValue->getTimestamp();
                        }
                    } else {
                        $titlesOld = ObjectUtility::implodeObjectStorageOnProperty($oldPropertyValue);
                        $titlesNew = ObjectUtility::implodeObjectStorageOnProperty($newPropertyValue);
                        if ($titlesOld !== $titlesNew) {
                            $dirtyProperties[$propertyName]['old'] = $titlesOld;
                            $dirtyProperties[$propertyName]['new'] = $titlesNew;
                        }
                    }
                }
            }
        }

        return $dirtyProperties;
    }

    /**
     * overwrite user with old values and xml with new values
     *
     * @param User $user
     * @param array $dirtyProperties
     * @return User $user
     */
    public static function rollbackUserWithChangeRequest($user, $dirtyProperties)
    {
        $existingProperties = $user->_getCleanProperties();

        // reset old values
        $user->setUserGroup($existingProperties['usergroup']);
        foreach ($dirtyProperties as $propertyName => $propertyValue) {
            $propertyValue = null;
            $user->{'set' . ucfirst($propertyName)}($existingProperties[$propertyName]);
        }

        // store changes as xml in field fe_users.tx_femanager_changerequest
        $user->setTxFemanagerChangerequest(GeneralUtility::array2xml($dirtyProperties, '', 0, 'changes'));

        return $user;
    }

    /**
     * Remove FE Session to a given user
     *
     * @param User $user
     */
    public static function removeFrontendSessionToUser(User $user)
    {
        self::getConnectionPool()->getConnectionForTable('fe_sessions')->delete(
            'fe_sessions',
            ['ses_userid' => (int)$user->getUid()]
        );
    }

    /**
     * Check if FE Session exists
     *
     * @param User $user
     * @return bool
     */
    public static function checkFrontendSessionToUser(User $user)
    {
        $queryBuilder = self::getConnectionPool()->getQueryBuilderForTable('fe_sessions');

        $row = $queryBuilder->select('ses_id')
            ->from('fe_sessions')
            ->where(
                $queryBuilder->expr()->eq('ses_userid', (int)$user->getUid())
            )
            ->execute()
            ->fetch();

        return !empty($row['ses_id']);
    }

    /**
     * Login FE-User
     *
     * @param User $user
     * @param string|null $storagePids
     */
    public static function login(User $user, $storagePids = null)
    {
        // ensure a session cookie is set (in case there is no session yet)
        $GLOBALS['TSFE']->fe_user->setAndSaveSessionData('dummy', true);
        // create the session (destroys all existing session data in the session backend!)
        $GLOBALS['TSFE']->fe_user->createUserSession(['uid' => (int)$user->getUid()]);
        // write the session data again to the session backend; preserves what was there before!!
        $GLOBALS['TSFE']->fe_user->setAndSaveSessionData('dummy', true);
    }
}
