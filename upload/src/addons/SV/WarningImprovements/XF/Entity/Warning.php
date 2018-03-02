<?php

namespace SV\WarningImprovements\XF\Entity;

use SV\WarningImprovements\Globals;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 *
 * @property string notes_
 *
 * GETTERS
 * @property \SV\WarningImprovements\XF\Entity\User|\XF\Entity\User|null anonymized_issuer
 * @property int expiry_date_rounded
 * @property \XF\Entity\WarningDefinition definition
 *
 * RELATIONS
 * @property \SV\WarningImprovements\XF\Entity\WarningDefinition|\XF\Entity\WarningDefinition Definition
 * @property \XF\Entity\WarningDefinition Definition_
 */
class Warning extends XFCP_Warning
{
    /**
     * @return int|null
     */
    public function getExpiryDateRounded()
    {
        $visitor = \XF::visitor();

        $expiryDateRound = $this->expiry_date;
        if (!$visitor->user_id ||
            $visitor->hasPermission('general', 'viewWarning'))
        {
            return $expiryDateRound;
        }

        if (!empty($expiryDateRound))
        {
            $expiryDateRound = ($expiryDateRound - ($expiryDateRound % 3600)) + 3600;
        }

        return $expiryDateRound;
    }

    /**
     * @return int|null
     */
    public function getNotes()
    {
        $visitor = \XF::visitor();

        if ($visitor->user_id && $visitor->hasPermission('general', 'viewWarning'))
        {
            return $this->notes_;
        }

        return '';
    }

    /**
     * @param string|null $error
     * @return bool
     */
    public function canView(&$error = null)
    {
        $visitor = \XF::visitor();

        if (!$visitor->user_id)
        {
            return false;
        }

        if ($visitor->user_id === $this->user_id && $this->app()->options()->sv_view_own_warnings)
        {
            return true;
        }

        return parent::canView($error);
    }

    /**
     * @param string|null $error
     * @return bool
     */
    public function canViewIssuer(&$error = null)
    {
        /** @var \SV\WarningImprovements\XF\Entity\User $visitor */
        $visitor = \XF::visitor();

        return $visitor->canViewIssuer($error);
    }

    /**
     * @return \SV\WarningImprovements\XF\Entity\User|\XF\Entity\User|Entity
     */
    public function getAnonymizedIssuer()
    {
        $anonymizedIssuer = null;

        if (!empty($anonymizeAsUserId = $this->app()->options()->sv_warningimprovements_warning_user))
        {
            if ($warningStaff = $this->em()->find('XF:User', $anonymizeAsUserId))
            {
                $anonymizedIssuer = $warningStaff;
            }
        }

        if (empty($anonymizedIssuer))
        {
            /** @var \XF\Repository\User $userRepo */
            $userRepo = $this->repository('XF:User');
            $anonymizedIssuer = $userRepo->getGuestUser(\XF::phrase('WarningStaff')->render());
        }

        return $anonymizedIssuer;
    }

    public function getDefinition()
    {
        if ($this->warning_definition_id === 0)
        {
            /** @var \SV\WarningImprovements\XF\Repository\Warning $warningRepo */
            $warningRepo = $this->repository('XF:Warning');

            return $warningRepo->getCustomWarningDefinition();
        }

        return $this->Definition_; // _ = bypass getter
    }

    public function verifyNotes($notes)
    {
        $options = \XF::options();
        if (!empty($minNoteLength = $options->sv_wi_warning_note_chars))
        {
            $noteLength = utf8_strlen($notes);
            if ($noteLength < $minNoteLength)
            {
                $underAmount = $minNoteLength - $noteLength;
                $this->error(\XF::phrase('sv_please_enter_note_with_at_least_x_characters', [
                    'count' => $minNoteLength,
                    'under' => $underAmount
                ]));

                return false;
            }
        }

        return true;
    }

    protected function updateUserWarningPoints(\XF\Entity\User $user, $adjustment, $isDelete = false)
    {
        Globals::$warningObj = $this;
        parent::updateUserWarningPoints($user, $adjustment, $isDelete);
    }

    protected function _postDelete()
    {
        parent::_postDelete();

        /** @var \XF\Repository\UserAlert $alertRepo */
        $alertRepo = $this->repository('XF:UserAlert');
        $alertRepo->fastDeleteAlertsForContent('warning', $this->warning_id);
    }

    /**
     * @param Structure $structure
     * @return Structure
     */
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $options = \XF::options();
        if ($options->sv_wi_require_warning_notes)
        {
            unset($structure->columns['notes']['default']);
            $structure->columns['notes']['required'] = 'sv_please_enter_note_for_warning';
        }

        $structure->getters['anonymized_issuer'] = true;
        $structure->getters['expiry_date_rounded'] = true;
        $structure->getters['notes'] = true;
        $structure->getters['definition'] = false;

        return $structure;
    }

    /**
     * @return \XF\Mvc\Entity\Repository|\XF\Repository\Warning|\SV\WarningImprovements\XF\Repository\Warning
     */
    protected function _getWarningRepo()
    {
        return $this->repository('XF:Warning');
    }
}
