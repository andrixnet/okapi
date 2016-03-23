<?php

namespace okapi\services\logs\images\edit;

use Exception;
use okapi\Okapi;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\Settings;
use okapi\BadRequest;
use okapi\services\logs\images\LogImagesCommon;


/**
 * This exception is thrown by WebService::_call method, when error is detected in
 * user-supplied data. It is not a BadRequest exception - it does not imply that
 * the Consumer did anything wrong (it's the user who did). This exception shouldn't
 * be used outside of this file.
 */
class CannotPublishException extends Exception {}

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 3
        );
    }

    /**
     * Edit an log entry image and return its (new) position.
     * Throws CannotPublishException or BadRequest on errors.
     */

    private static function _call(OkapiRequest $request)
    {
        # Developers! Please notice the fundamental difference between throwing
        # CannotPublishException and the "standard" BadRequest/InvalidParam
        # exceptions. CannotPublishException will be caught by the service's
        # call() function and returns a message to be displayed to the user.

        require_once('log_images_common.inc.php');

        # validate the 'image_uuid' parameter

        $image_uuid = $request->get_parameter('image_uuid');
        if (!$image_uuid)
            throw new ParamMissing('image_uuid');

        # When uploading images, OCPL stores the user_id of the uploader
        # in the 'pictures' table. This is redundant to cache_logs.user_id,
        # because only the log entry author may append images. We will stick
        # to log_entries.user_id here, which is the original value and works
        # for all OC branches, and ignore pictures.user_id.

        $rs = Db::query("
            select
                cache_logs.id,
                cache_logs.user_id
            from cache_logs
            join pictures on pictures.object_id = cache_logs.id
            where pictures.object_type = 1 and pictures.uuid = '".Db::escape_string($image_uuid)."'
        ");
        $row = Db::fetch_assoc($rs);
        Db::free_result($rs);
        if (!$row) {
            throw new InvalidParam(
                'image_uuid',
                "There is no log entry image with uuid '".$image_uuid."'."
            );
        }
        $log_internal_id = $row['id'];
        $log_user_internal_id = $row['user_id'];
        if ($log_user_internal_id != $request->token->user_id) {
            throw new InvalidParam(
                'image_uuid',
                "The user of your access token is not the author of the associated log entry."
            );
        }
        unset($row);

        # validate the 'caption', 'is_spoiler' and 'position' parameters

        $caption = $request->get_parameter('caption');
        if ($caption !== null && $caption == '') {
            throw new CannotPublishException(sprintf(
                _("%s requires a non-empty image caption."),
                Okapi::get_normalized_site_name()
            ));
        }

        $is_spoiler = $request->get_parameter('is_spoiler');
        if ($is_spoiler !== null) {
            if (!in_array($is_spoiler, array('true', 'false')))
                throw new InvalidParam('is_spoiler');
        }

        $position = $request->get_parameter('position');
        if ($position !== null && !preg_match("/^-?[0-9]+$/", $position)) {
            throw new InvalidParam('position', "'".$position."' is not an integer number.");
        }

        if ($caption === null && $is_spoiler === null && $position === null) {
            # If no-params were allowed, what would be the success message?
            # It's more reasonable to assume that this was a developer's error.
            throw new BadRequest(
                "At least one of the parameters 'caption', 'is_spoiler' and 'position' must be supplied"
            );
        }

        $image_uuid_escaped = Db::escape_string($image_uuid);

        # update caption
        if ($caption !== null) {
            Db::execute("
                update pictures
                set title = '".Db::escape_string($caption)."'
                where uuid = '".$image_uuid_escaped."'
            ");
        }

        # update spoiler flag
        if ($is_spoiler !== null) {
            Db::execute("
                update pictures
                set spoiler = ".($is_spoiler == 'true' ? 1 : 0)."
                where uuid = '".$image_uuid_escaped."'
            ");
        }

        # update position
        if ($position !== null)
        {
            if (Settings::get('OC_BRANCH') == 'oc.pl')
            {
                # OCPL as no arbitrary log picture ordering => ignore position parameter
                # and return the picture's current position.

                $image_uuids = Db::select_column("
                    select uuid from pictures
                    where object_type = 1 and object_id = '".Db::escape_string($log_internal_id)."'
                    order by date_created
                ");
                $position = array_search($image_uuid, $image_uuids);
            }
            else
            {
                list($position, $seq) = LogImagesCommon::prepare_position(
                    $log_internal_id,
                    $position,
                    0
                );
                # For OCDE the pictures table is write locked now.

                $old_seq = DB::select_value("
                    select seq from pictures where uuid = '".$image_uuid_escaped."'
                ");

                if ($seq != $old_seq)
                {
                    # First move the edited picture to the end, to make space for rotating.
                    # Remember that we have no transactions at OC.de. If something goes wrong,
                    # the image will stay at the end of the list.

                    $max_seq = Db::select_value("
                        select max(seq)
                        from pictures
                        where object_type = 1 and object_id = '".Db::escape_string($log_internal_id)."'
                    ");

                    Db::query("
                        update pictures
                        set seq = '".Db::escape_string($max_seq + 1)."'
                        where uuid = '".$image_uuid_escaped."'
                    ");

                    # now move the pictures inbetween
                    if ($seq < $old_seq) {
                        Db::execute("
                            update pictures
                            set seq = seq + 1
                            where
                                object_type = 1
                                and object_id = '".Db::escape_string($log_internal_id)."'
                                and seq >= '".Db::escape_string($seq)."'
                                and seq < '".Db::escape_string($old_seq)."'
                            order by seq desc
                        ");
                    } else {
                        Db::execute("
                            update pictures
                            set seq = seq - 1
                            where
                                object_type = 1
                                and object_id = '".Db::escape_string($log_internal_id)."'
                                and seq <= '".Db::escape_string($seq)."'
                                and seq > '".Db::escape_string($old_seq)."'
                            order by seq asc
                        ");
                    }

                    # and finally move the edited picture into place
                    Db::query("
                        update pictures
                        set seq = '".Db::escape_string($seq)."'
                        where uuid = '".$image_uuid_escaped."'
                    ");
                }

                Db::execute('unlock tables');
            }
        }

        return $position;
    }

    public static function call(OkapiRequest $request)
    {
        # This is the "real" entry point. A wrapper for the _call method.

        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        Okapi::gettext_domain_init(explode("|", $langpref));

        try
        {
            $position = self::_call($request);
            $result = array(
                'success' => true,
                'message' => _("Your log entry image has been updated."),
                'position' => $position
            );
            Okapi::gettext_domain_restore();
        }
        catch (CannotPublishException $e)
        {
            Okapi::gettext_domain_restore();
            $result = array(
                'success' => false,
                'message' => $e->getMessage(),
                'position' => null
            );
        }

        return Okapi::formatted_response($request, $result);
    }

}
