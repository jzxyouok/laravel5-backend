<?php

namespace App\Repositories\Backend\User;

use App\Models\User;
use App\Repositories\Backend\Role\RoleContract;
use App\Services\Registrar;
use App\Exceptions\GeneralException;
use App\Exceptions\UserNotFoundException;
use Illuminate\Support\Facades\Auth;

/**
 * Class UserRepository
 * @package App\Repositories
 */
class UserRepository implements UserContract
{

    /**
     * @var RoleContract
     */
    protected $role;

    /**
     * @param RoleContract           $role
     * @internal param User $user
     */
    public function __construct(RoleContract $role)
    {
        $this->role = $role;
    }

    /**
     * get user by id
     * @param            $id
     * @param bool|false $withRoles
     * @return array|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Model|null
     */
    public function find($id, $withRoles = false) {
        if ($withRoles) {
            $user = User::with('roles')->withTrashed()->find($id);
        } else {
            $user = User::withTrashed()->find($id);
        }
        if (! is_null($user)) return $user;
        return array();
    }

    /**
     * @param $per_page
     * @param string $order_by
     * @param string $sort
     * @param int $status
     * @return mixed
     */
    public function getUsersPaginated($per_page, $status = 1, $order_by = 'id', $sort = 'asc') {
        return User::where('status', $status)->orderBy($order_by, $sort)->paginate($per_page);
    }

    /**
     * @param $per_page
     * @return \Illuminate\Pagination\Paginator
     */
    public function getDeletedUsersPaginated($per_page) {
        return User::onlyTrashed()->paginate($per_page);
    }

    /**
     * @param string $per_page
     * @param string $order_by
     * @param string $sort
     * @return mixed
     * @internal param $
     */
    public function getAllUsers($per_page, $order_by = 'id', $sort = 'asc') {
        return User::orderBy($order_by, $sort)->paginate($per_page);
    }

    /**
     * @param $input
     * @param $roles
     * @return bool
     * @throws GeneralException
     * @throws UserNeedsRolesException
     */
    public function create($input, $roles) {
        $user = $this->createUserStub($input);
        if ($user->save()) {
            //User Created, Validate Roles
            $this->validateRoleAmount($user, $roles['assignees_roles']);
            //Attach new roles
            $user->attachRoles($roles['assignees_roles']);
            //Attach other permissions
            //$user->attachPermissions($permissions['permission_user']);
            //Send confirmation email if requested
//            if (isset($input['confirmation_email']) && $user->confirmed == 0)
//                $this->registrar->resendConfirmationEmail($user->id);
            return true;
        }
        throw new GeneralException('There was a problem creating this user. Please try again.');
    }

    /**
     * @param $id
     * @param $input
     * @param $roles
     * @return bool
     * @throws GeneralException
     */
    public function update($id, $input, $roles=array()) {
        $user = $this->find($id);
        $this->checkUserByEmail($input, $user);
        if ($user->update($input)) {
            //For whatever reason this just wont work in the above call, so a second is needed for now
            $user->status = isset($input['status']) ? 1 : 0;
//            $user->confirmed = isset($input['confirmed']) ? 1 : 0;
            $user->save();
            $this->checkUserRolesCount($roles);
            $this->flushRoles($roles, $user);
//            $this->flushPermissions($permissions, $user);
            return true;
        }
        throw new GeneralException('There was a problem updating this user. Please try again.');
    }

    /**
     * @param $id
     * @param $input
     * @return bool
     * @throws GeneralException
     */
    public function updatePassword($id, $input) {
        $user = $this->find($id);
        //Passwords are hashed on the model
        $user->password = $input['password'];
        if ($user->save())
            return true;
        throw new GeneralException('There was a problem changing this users password. Please try again.');
    }

    /**
     * @param $id
     * @return bool
     * @throws GeneralException
     */
    public function destroy($id) {
        if (auth()->id() == $id)
            throw new GeneralException("You can not delete yourself.");
        $user = $this->find($id);
        if ($user->delete())
            return true;
        throw new GeneralException("There was a problem deleting this user. Please try again.");
    }

    /**
     * @param $id
     * @return boolean|null
     * @throws GeneralException
     */
    public function delete($id) {
        $user = $this->find($id, true);
        //Detach all roles & permissions
        $user->detachRoles($user->roles);
        $user->detachPermissions($user->permissions);
        try {
            $user->forceDelete();
        } catch (\Exception $e) {
            throw new GeneralException($e->getMessage());
        }
    }

    /**
     * @param $id
     * @return bool
     * @throws GeneralException
     */
    public function restore($id) {
        $user = $this->find($id);
        if ($user->restore())
            return true;
        throw new GeneralException("There was a problem restoring this user. Please try again.");
    }
    /**
     * @param $id
     * @param $status
     * @return bool
     * @throws GeneralException
     */
    public function mark($id, $status) {
        if (auth()->id() == $id && ($status == 0 || $status == 2))
            throw new GeneralException("You can not do that to yourself.");
        $user = $this->find($id);
        $user->status = $status;
        if ($user->save())
            return true;
        throw new GeneralException("There was a problem updating this user. Please try again.");
    }

    /**
     * Check to make sure at lease one role is being applied or deactivate user
     * @param $user
     * @param $roles
     * @throws UserNeedsRolesException
     */
    private function validateRoleAmount($user, $roles) {
        //Validate that there's at least one role chosen, placing this here so
        //at lease the user can be updated first, if this fails the roles will be
        //kept the same as before the user was updated
        if (count($roles) == 0) {
            //Deactivate user
            $user->status = 0;
            $user->save();
            $exception = new UserNeedsRolesException();
            $exception->setValidationErrors('You must choose at lease one role. User has been created but deactivated.');
            //Grab the user id in the controller
            $exception->setUserID($user->id);
            throw $exception;
        }
    }

    /**
     * @param $input
     * @param $user
     * @throws GeneralException
     */
    private function checkUserByEmail($input, $user)
    {
        //Figure out if email is not the same
        if ($user->email != $input['email'])
        {
            //Check to see if email exists
            if (User::where('email', '=', $input['email'])->first())
                throw new GeneralException('That email address belongs to a different user.');
        }
    }
    /**
     * @param $roles
     * @param $user
     */
    private function flushRoles($roles, $user)
    {
        //Flush roles out, then add array of new ones
        $user->detachRoles($user->roles);
        $user->attachRoles($roles['assignees_roles']);
    }

    /**
     * @param $permissions
     * @param $user
     */
    private function flushPermissions($permissions, $user)
    {
        //Flush permissions out, then add array of new ones if any
        $user->detachPermissions($user->permissions);
        if (count($permissions['permission_user']) > 0)
            $user->attachPermissions($permissions['permission_user']);
    }
    /**
     * @param $roles
     * @throws GeneralException
     */
    private function checkUserRolesCount($roles)
    {
        //User Updated, Update Roles
        //Validate that there's at least one role chosen
        if (count($roles['assignees_roles']) == 0)
            throw new GeneralException('You must choose at least one role.');
    }
    /**
     * @param $input
     * @return mixed
     */
    private function createUserStub($input)
    {
        $user = new User;
        $user->username = $input['username'];
        $user->email = $input['email'];
        //$user->password = $input['password'];
        $user->status = isset($input['status']) ? 1 : 0;
//        $user->confirmation_code = md5(uniqid(mt_rand(), true));
//        $user->confirmed = isset($input['confirmed']) ? 1 : 0;
        return $user;
    }
}