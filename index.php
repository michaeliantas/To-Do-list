<?php
session_start();

$users_file = __DIR__ . '/users.json';

function load_users() {
    global $users_file;
    if (file_exists($users_file)) {
        $users = json_decode(file_get_contents($users_file), true) ?: [];
        // Ensure each user has profile and migrate old format
        foreach ($users as $username => &$user) {
            if (is_string($user)) {
                // Old format: username => hash
                $user = [
                    'password' => $user,
                    'profile' => ['name' => '', 'lastname' => '', 'birthdate' => '', 'description' => '']
                ];
            } elseif (!isset($user['profile'])) {
                $user['profile'] = ['name' => '', 'lastname' => '', 'birthdate' => '', 'description' => ''];
            }
        }
        return $users;
    }
    return [];
}

function save_users($users) {
    global $users_file;
    file_put_contents($users_file, json_encode($users));
}

if (!isset($_SESSION['logged_in'])) {
    $_SESSION['logged_in'] = false;
}

if (!isset($_SESSION['todo_lists'])) {
    $_SESSION['todo_lists'] = [];
}

if (!isset($_SESSION['deleted_batches_per_user'])) {
    $_SESSION['deleted_batches_per_user'] = [];
}

if ($_SESSION['logged_in'] && isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
    if (!isset($_SESSION['todo_lists'][$username])) {
        $_SESSION['todo_lists'][$username] = [];
    }
    if (!isset($_SESSION['deleted_batches_per_user'][$username])) {
        $_SESSION['deleted_batches_per_user'][$username] = [];
    }
    // For migration, if old format
    if (!empty($_SESSION['todo_lists'][$username]) && isset($_SESSION['todo_lists'][$username][0]) && is_string($_SESSION['todo_lists'][$username][0])) {
        $new = [];
        foreach ($_SESSION['todo_lists'][$username] as $text) {
            $new[] = ['text' => $text, 'pinned' => false];
        }
        $_SESSION['todo_lists'][$username] = $new;
    }
    if (!empty($_SESSION['deleted_batches_per_user'][$username]) && isset($_SESSION['deleted_batches_per_user'][$username][0][0]) && is_string($_SESSION['deleted_batches_per_user'][$username][0][0])) {
        $new_batches = [];
        foreach ($_SESSION['deleted_batches_per_user'][$username] as $batch) {
            $new_batch = [];
            foreach ($batch as $text) {
                $new_batch[] = ['text' => $text, 'pinned' => false];
            }
            $new_batches[] = $new_batch;
        }
        $_SESSION['deleted_batches_per_user'][$username] = $new_batches;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['signup'])) {
        $users = load_users();
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        if (empty($username) || empty($password)) {
            $error = 'Please fill all fields';
        } elseif (isset($users[$username])) {
            $error = 'Username already exists';
        } else {
            $users[$username] = [
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'profile' => ['name' => '', 'lastname' => '', 'birthdate' => '', 'description' => '']
            ];
            save_users($users);
            header('Location: index.php');
            exit;
        }
    } elseif (isset($_POST['login'])) {
        $users = load_users();
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        if (isset($users[$username]) && password_verify($password, $users[$username]['password'])) {
            $_SESSION['logged_in'] = true;
            $_SESSION['username'] = $username;
            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid username or password';
        }
    } elseif (isset($_POST['logout'])) {
        $_SESSION['logged_in'] = false;
        unset($_SESSION['username']);
        header('Location: index.php');
        exit;
    } elseif ($_SESSION['logged_in']) {
        $username = $_SESSION['username'];
        $todo_list = &$_SESSION['todo_lists'][$username];
        $deleted_batches = &$_SESSION['deleted_batches_per_user'][$username];
        if (isset($_POST['delete']) && isset($_POST['delete_items'])) {
            $deleted = [];
            foreach ($_POST['delete_items'] as $index) {
                $index = (int)$index;
                if (isset($todo_list[$index])) {
                    $deleted[] = $todo_list[$index];
                    unset($todo_list[$index]);
                }
            }
            if (!empty($deleted)) {
                $deleted_batches[] = $deleted;
            }
            $todo_list = array_values($todo_list); // reindex
        } elseif (isset($_POST['restore'])) {
            if (!empty($deleted_batches)) {
                $last_batch = array_pop($deleted_batches);
                $todo_list = array_merge($todo_list, $last_batch);
            }
        } elseif (isset($_POST['pin']) && isset($_POST['delete_items'])) {
            $set_pinned = ($_POST['pin'] == 'Unpin Selected') ? false : true;
            foreach ($_POST['delete_items'] as $index) {
                $index = (int)$index;
                if (isset($todo_list[$index])) {
                    $todo_list[$index]['pinned'] = $set_pinned;
                }
            }
        } elseif (isset($_POST['add'])) {
            foreach ($_POST['things'] as $thing) {
                if (!empty($thing)) {
                    $todo_list[] = ['text' => htmlspecialchars($thing), 'pinned' => false];
                }
            }
        } elseif (isset($_POST['save_profile'])) {
            $users = load_users();
            $username = $_SESSION['username'];
            $users[$username]['profile'] = [
                'name' => trim($_POST['name']),
                'lastname' => trim($_POST['lastname']),
                'birthdate' => trim($_POST['birthdate']),
                'description' => trim($_POST['description'])
            ];
            save_users($users);
            header('Location: index.php?action=profile');
            exit;
        } elseif (isset($_POST['delete_account'])) {
            $users = load_users();
            $username = $_SESSION['username'];
            unset($users[$username]);
            save_users($users);
            unset($_SESSION['todo_lists'][$username]);
            unset($_SESSION['deleted_batches_per_user'][$username]);
            $_SESSION['logged_in'] = false;
            unset($_SESSION['username']);
            header('Location: index.php');
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>To-Do List</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h2 {
            color: #333;
            text-align: center;
        }
        form {
            display: flex;
            flex-direction: column;
        }
        label {
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"], input[type="password"], textarea {
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        button, input[type="submit"] {
            padding: 10px;
            margin-bottom: 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: transform 0.1s;
        }
        button:hover, input[type="submit"]:hover {
            transform: scale(1.05);
        }
        .shake {
            animation: shake 0.5s;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        .pulse {
            animation: pulse 0.3s;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        button {
            background-color: #2196F3;
            color: white;
        }
        input[type="submit"] {
            background-color: #4CAF50;
            color: white;
        }
        input[type="submit"][name="delete"] {
            background-color: #f44336;
        }
        input[type="submit"][name="pin"] {
            background-color: #FF9800;
        }
        input[type="submit"][name="restore"] {
            background-color: #9C27B0;
        }
        input[type="submit"][name="logout"] {
            background-color: #607D8B;
        }
        ul {
            list-style-type: none;
            padding: 0;
        }
        li {
            background-color: #f9f9f9;
            margin-bottom: 5px;
            padding: 10px;
            border-radius: 5px;
            display: flex;
            align-items: center;
        }
        li[data-pinned] {
            background-color: #e0f7fa;
            border-left: 5px solid #00BCD4;
        }
        li input[type="checkbox"] {
            margin-right: 10px;
        }
        p {
            text-align: center;
            margin: 10px 0;
        }
        a {
            color: #2196F3;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        .error {
            color: red;
            text-align: center;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>

<?php if (!$_SESSION['logged_in']): 
    $users = load_users();
    $action = $_GET['action'] ?? (empty($users) ? 'signup' : 'login');
    if ($action == 'signup'): ?>

<div class="container">
<h2>Sign Up</h2>
<?php if ($error): ?>
<p class="error"><?php echo $error; ?></p>
<?php endif; ?>
<form method="post">
    <label for="username">Username:</label>
    <input type="text" id="username" name="username" required>
    <label for="password">Password:</label>
    <input type="password" id="password" name="password" required>
    <input type="submit" name="signup" value="Sign Up">
</form>
<p>Already have an account? <a href="?action=login">Login</a></p>
</div>

<?php else: ?>

<div class="container">
<h2>Login</h2>
<?php if ($error): ?>
<p class="error"><?php echo $error; ?></p>
<?php endif; ?>
<form method="post">
    <label for="username">Username:</label>
    <input type="text" id="username" name="username" required>
    <label for="password">Password:</label>
    <input type="password" id="password" name="password" required>
    <input type="submit" name="login" value="Login">
</form>
<p>Don't have an account? <a href="?action=signup">Sign Up</a></p>
</div>

<?php endif; ?>

<?php elseif ($_GET['action'] ?? '' == 'profile'): 
    $users = load_users();
    $username = $_SESSION['username'];
    $profile = $users[$username]['profile'];
?>

<div class="container">
<h2>Profile</h2>
<form method="post">
    <label for="name">First Name:</label>
    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($profile['name']); ?>">
    <label for="lastname">Last Name:</label>
    <input type="text" id="lastname" name="lastname" value="<?php echo htmlspecialchars($profile['lastname']); ?>">
    <label for="birthdate">Birth Date:</label>
    <input type="date" id="birthdate" name="birthdate" value="<?php echo htmlspecialchars($profile['birthdate']); ?>">
    <label for="description">Description:</label>
    <textarea id="description" name="description" rows="4"><?php echo htmlspecialchars($profile['description']); ?></textarea>
    <input type="submit" name="save_profile" value="Save Profile">
</form>
<form method="post" onsubmit="return confirm('Are you sure you want to delete your account? This action cannot be undone.');">
    <input type="submit" name="delete_account" value="Delete Account" style="background-color: #f44336;">
</form>
<p><a href="index.php">Back to To-Do List</a></p>
</div>

<?php else: ?>

<div class="container">
<form action="index.php" method="post">
    <h2>Your To-Do List</h2>

    <?php
    $username = $_SESSION['username'];
    $todo_list = $_SESSION['todo_lists'][$username];
    if (!empty($todo_list)) {
        $pinned = [];
        $unpinned = [];
        foreach ($todo_list as $index => $item) {
            if ($item['pinned']) {
                $pinned[] = ['index' => $index, 'text' => $item['text']];
            } else {
                $unpinned[] = ['index' => $index, 'text' => $item['text']];
            }
        }
        echo "<ul>";
        foreach ($pinned as $p) {
            echo "<li data-pinned><input type='checkbox' name='delete_items[]' value='{$p['index']}'> => {$p['text']}</li>";
        }
        foreach ($unpinned as $u) {
            echo "<li><input type='checkbox' name='delete_items[]' value='{$u['index']}'> {$u['text']}</li>";
        }
        echo "</ul>";
    }
    ?>

    <label for="Things to do">Things to do:</label>
    <input type="text" id="Things to do" name="things[]">
    <button type="button" id="addButton">Add Another</button>
    <input type="submit" name="pin" value="Pin Selected" id="pinButton">

    <?php
    if (!empty($todo_list)) {
        echo "<input type='submit' name='delete' value='Delete Selected'>";
    }
    ?>

    <input type="submit" name="add" value="Submit">

    <?php
    $deleted_batches = $_SESSION['deleted_batches_per_user'][$username];
    if (!empty($deleted_batches)) {
        echo "<input type='submit' name='restore' value='Restore'>";
    }
    ?>

    <input type="submit" name="logout" value="Logout">
    <a href="?action=profile" style="display: inline-block; padding: 10px; background-color: #2196F3; color: white; text-decoration: none; border-radius: 5px; margin-top: 10px;">Profile</a>
</form>
</div>

<script>
if (document.getElementById('addButton')) {
    document.getElementById('addButton').addEventListener('click', function() {
        var form = document.querySelector('form');
        var newInput = document.createElement('input');
        newInput.type = 'text';
        newInput.name = 'things[]';
        newInput.placeholder = 'Another thing to do';
        form.insertBefore(newInput, document.getElementById('addButton'));
        form.insertBefore(document.createElement('br'), document.getElementById('addButton'));
    });
}

if (document.getElementById('pinButton')) {
    document.querySelectorAll('input[name="delete_items[]"]').forEach(cb => {
        cb.addEventListener('change', updateButton);
    });
}

function updateButton() {
    let hasPinned = false;
    document.querySelectorAll('input[name="delete_items[]"]:checked').forEach(cb => {
        if (cb.closest('li').hasAttribute('data-pinned')) {
            hasPinned = true;
        }
    });
    document.getElementById('pinButton').value = hasPinned ? 'Unpin Selected' : 'Pin Selected';
}

document.querySelectorAll('button, input[type="submit"]').forEach(btn => {
    btn.addEventListener('click', function() {
        this.classList.add('pulse');
        setTimeout(() => {
            this.classList.remove('pulse');
        }, 300);
    });
});
</script>

<?php endif; ?>

</body>
</html>