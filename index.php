<?php
$session_dir = __DIR__ . '/sessions';
if (!is_dir($session_dir)) {
    mkdir($session_dir, 0777, true);
}
session_save_path($session_dir);
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

function normalize_expiry($input) {
    $input = trim($input);
    if ($input === '' || $input === '0000-00-00') {
        return '';
    }
    // For date input, it's already YYYY-MM-DD, validate it
    $date = DateTime::createFromFormat('Y-m-d', $input);
    if ($date && $date->format('Y-m-d') === $input) {
        return $input;
    }
    return '';
}

function display_expiry($expiry) {
    if (empty($expiry)) {
        return '';
    }
    $date = DateTime::createFromFormat('Y-m-d', $expiry);
    return $date ? $date->format('j/n/Y') : $expiry;
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
            $new[] = ['text' => $text, 'pinned' => false, 'expiry' => '', 'category' => ''];
        }
        $_SESSION['todo_lists'][$username] = $new;
    }
    if (!empty($_SESSION['deleted_batches_per_user'][$username]) && isset($_SESSION['deleted_batches_per_user'][$username][0][0]) && is_string($_SESSION['deleted_batches_per_user'][$username][0][0])) {
        $new_batches = [];
        foreach ($_SESSION['deleted_batches_per_user'][$username] as $batch) {
            $new_batch = [];
            foreach ($batch as $text) {
                $new_batch[] = ['text' => $text, 'pinned' => false, 'expiry' => '', 'category' => ''];
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
        if (isset($_POST['reorder']) && isset($_POST['order'])) {
            $ordered = [];
            foreach ($_POST['order'] as $index) {
                $index = (int)$index;
                if (isset($todo_list[$index])) {
                    $ordered[] = $todo_list[$index];
                }
            }
            if (count($ordered) === count($todo_list)) {
                $todo_list = $ordered;
            }
            exit;
        } elseif (isset($_POST['delete']) && isset($_POST['delete_items'])) {
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
                $_SESSION['notification'] = count($deleted) === 1 ? 'Task deleted' : count($deleted) . ' tasks deleted';
                $_SESSION['notification_type'] = 'delete';
            }
            $todo_list = array_values($todo_list); // reindex
        } elseif (isset($_POST['restore'])) {
            if (!empty($deleted_batches)) {
                $last_batch = array_pop($deleted_batches);
                $todo_list = array_merge($todo_list, $last_batch);
                $_SESSION['notification'] = count($last_batch) === 1 ? 'Task restored' : count($last_batch) . ' tasks restored';
                $_SESSION['notification_type'] = 'restore';
            }
        } elseif (isset($_POST['pin']) && isset($_POST['delete_items'])) {
            $set_pinned = ($_POST['pin'] == 'Unpin Selected') ? false : true;
            foreach ($_POST['delete_items'] as $index) {
                $index = (int)$index;
                if (isset($todo_list[$index])) {
                    $todo_list[$index]['pinned'] = $set_pinned;
                }
            }
        } elseif ((isset($_POST['edit']) && isset($_POST['edit_index']) && isset($_POST['things'][0])) || isset($_POST['clear_expiry'])) {
        $editIndex = (int)$_POST['edit_index'];
        if (isset($_POST['clear_expiry'])) {
        $newText = $todo_list[$editIndex]['text'];
        $newExpiry = '';
        $newCategory = $todo_list[$editIndex]['category'];
        } else {
        $newText = trim($_POST['things'][0]);
        $newExpiry = normalize_expiry($_POST['expiry'][0] ?? '');
        $newCategory = trim($_POST['categories'][0] ?? '');
        }

        if (isset($todo_list[$editIndex])) {
        $todo_list[$editIndex]['text'] = htmlspecialchars($newText);
        $todo_list[$editIndex]['expiry'] = $newExpiry;
        $todo_list[$editIndex]['category'] = htmlspecialchars($newCategory);
        }
        } elseif (isset($_POST['add'])) {
            $added_count = 0;
            foreach ($_POST['things'] as $i => $thing) {
                $expiry = normalize_expiry($_POST['expiry'][$i] ?? '');
                $category = trim($_POST['categories'][$i] ?? '');
                if (!empty($thing)) {
                    $todo_list[] = ['text' => htmlspecialchars($thing), 'pinned' => false, 'expiry' => $expiry, 'category' => htmlspecialchars($category)];
                    $added_count++;
                }
            }
            if ($added_count > 0) {
                $_SESSION['notification'] = $added_count === 1 ? 'Task added' : $added_count . ' tasks added';
                $_SESSION['notification_type'] = 'add';
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
        input:disabled {
            opacity: 0.5;
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
        
        input[type="submit"][name="add"] {
        background-color: #4CAF50;
        color: white;
        }

        input[type="submit"][name="edit"],
        input[type="submit"][name="save_profile"] {
         background-color: #00ff2a;
          color: black;
        }
    
        input[type="submit"][name="login"] {
         background-color: #2196F3;
        color: white;
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
            cursor: grab;
        }
        li.dragging {
            opacity: 0.55;
            cursor: grabbing;
        }
        li[data-pinned] {
            background-color: #e0f7fa;
            border-left: 5px solid #00BCD4;
        }
        li.expired span {
            color: red;
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
        .notification {
            border-radius: 5px;
            margin-bottom: 10px;
            padding: 10px;
            text-align: center;
        }
        .notification.add {
            background-color: #e8f5e9;
            border: 1px solid #81c784;
            color: #256029;
        }
        .notification.delete {
            background-color: #ffebee;
            border: 1px solid #e57373;
            color: #b71c1c;
        }
        .notification.restore {
            background-color: #f3e5f5;
            border: 1px solid #ba68c8;
            color: #6a1b9a;
        }
        .notification.hide {
            display: none;
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
    <?php if (!empty($_SESSION['notification'])): ?>
    <!-- Lightweight UI notification for task actions. -->
    <p class="notification <?php echo htmlspecialchars($_SESSION['notification_type'] ?? 'add'); ?>" id="notification"><?php echo htmlspecialchars($_SESSION['notification']); ?></p>
    <?php unset($_SESSION['notification']); ?>
    <?php unset($_SESSION['notification_type']); ?>
    <?php endif; ?>

    <?php if (!empty($todo_list)): ?>
    <label for="search">Search:</label>
    <input type="text" id="search" placeholder="Search todos...">
    <?php endif; ?>

    <?php
    $username = $_SESSION['username'];
    $todo_list = $_SESSION['todo_lists'][$username];
    if (!empty($todo_list)) {
        $pinned = [];
        $unpinned = [];
        foreach ($todo_list as $index => $item) {
            if ($item['pinned']) {
                $pinned[] = ['index' => $index, 'text' => $item['text'], 'expiry' => $item['expiry'] ?? '', 'category' => $item['category'] ?? ''];
            } else {
                $unpinned[] = ['index' => $index, 'text' => $item['text'], 'expiry' => $item['expiry'] ?? '', 'category' => $item['category'] ?? ''];
            }
        }
        echo "<ul id='todoList'>";
        foreach ($pinned as $p) {
            $isExpired = false;
            if (!empty($p['expiry'])) {
                $expiryDate = DateTime::createFromFormat('Y-m-d', $p['expiry']);
                $isExpired = $expiryDate && $expiryDate < new DateTime('today');
            }
            $expiredClass = $isExpired ? ' class="expired"' : '';
            $dueText = $p['expiry'] ? " <span style='font-size:0.9em; color:#555;'>(Due: " . display_expiry($p['expiry']) . ")</span>" : '';
            $categoryText = $p['category'] ? " <span style='font-weight:bold; color:#007bff;'>[{$p['category']}]</span>" : '';
            $style = $isExpired ? ' style="color: red;"' : '';
            echo "<li draggable='true' data-index='{$p['index']}' data-pinned data-expiry='{$p['expiry']}' data-category='{$p['category']}'{$expiredClass}>
                    <input type='checkbox' name='delete_items[]' value='{$p['index']}'>
                    <span{$style}>&#8658; {$categoryText} {$p['text']}{$dueText}</span>
                  </li>";
        }
        foreach ($unpinned as $u) {
            $isExpired = false;
            if (!empty($u['expiry'])) {
                $expiryDate = DateTime::createFromFormat('Y-m-d', $u['expiry']);
                $isExpired = $expiryDate && $expiryDate < new DateTime('today');
            }
            $expiredClass = $isExpired ? ' class="expired"' : '';
            $dueText = $u['expiry'] ? " <span style='font-size:0.9em; color:#555;'>(Due: " . display_expiry($u['expiry']) . ")</span>" : '';
            $categoryText = $u['category'] ? " <span style='font-weight:bold; color:#007bff;'>[{$u['category']}]</span>" : '';
            $style = $isExpired ? ' style="color: red;"' : '';
            echo "<li draggable='true' data-index='{$u['index']}' data-expiry='{$u['expiry']}' data-category='{$u['category']}'{$expiredClass}>
                    <input type='checkbox' name='delete_items[]' value='{$u['index']}'>
                    <span{$style}>{$categoryText} {$u['text']}{$dueText}</span>
                  </li>";
        }
        echo "</ul>";
    }
    ?>

    <label for="Things to do">Things to do:</label>
    <input type="text" id="Things to do" name="things[]">
    <label for="category" id="categoryLabel" style="display:none;">Category:</label>
    <select id="category" name="categories[]" style="display:none;">
        <option value="">None</option>
        <option value="Work">Work</option>
        <option value="Personal">Personal</option>
        <option value="Shopping">Shopping</option>
        <option value="Health">Health</option>
        <option value="Education">Education</option>
        <option value="Finance">Finance</option>
        <option value="Travel">Travel</option>
        <option value="Home">Home</option>
    </select>
    <label for="expiry" id="expiryLabel" style="display:none;">Expiry Date:</label>
    <input type="date" id="expiry" name="expiry[]" style="display:none;">
    <button type="submit" name="clear_expiry" id="clearExpiry" style="display:none;">Clear Expiry</button>
    <input type="hidden" id="editIndex" name="edit_index" value="">
    <button type="button" id="addButton" onclick="addTaskInput()">Add Another</button>
    <input type="submit" name="edit" id="editButton" value="Save Edit" disabled>
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
const addButton = document.getElementById('addButton');
const editButton = document.getElementById('editButton');
const pinButton = document.getElementById('pinButton');
const editIndexInput = document.getElementById('editIndex');
const todoInput = document.getElementById('Things to do');
const expiryInput = document.getElementById('expiry');
const clearExpiryButton = document.getElementById('clearExpiry');
const categoryInput = document.getElementById('category');
const categoryLabel = document.getElementById('categoryLabel');

function addTaskInput() {
    const form = document.querySelector('form');
    const lastThingInput = Array.from(form.querySelectorAll('input[name="things[]"]')).pop();
    const newInput = document.createElement('input');
    newInput.type = 'text';
    newInput.name = 'things[]';
    newInput.placeholder = 'Another thing to do';
    const categoryLabel = document.createElement('label');
    categoryLabel.textContent = 'Category:';
    const categorySelect = document.createElement('select');
    categorySelect.name = 'categories[]';
    categorySelect.innerHTML = `
        <option value="">None</option>
        <option value="Work">Work</option>
        <option value="Personal">Personal</option>
        <option value="Shopping">Shopping</option>
        <option value="Health">Health</option>
        <option value="Education">Education</option>
        <option value="Finance">Finance</option>
        <option value="Travel">Travel</option>
        <option value="Home">Home</option>
    `;
    const br = document.createElement('br');
    if (lastThingInput) {
        lastThingInput.parentNode.insertBefore(newInput, lastThingInput.nextSibling);
        newInput.parentNode.insertBefore(categoryLabel, newInput.nextSibling);
        categoryLabel.parentNode.insertBefore(categorySelect, categoryLabel.nextSibling);
        categorySelect.parentNode.insertBefore(br, categorySelect.nextSibling);
    } else {
        form.insertBefore(newInput, document.getElementById('addButton'));
        form.insertBefore(categoryLabel, document.getElementById('addButton'));
        form.insertBefore(categorySelect, document.getElementById('addButton'));
        form.insertBefore(br, document.getElementById('addButton'));
    }
}

function setActionMode() {
    const checked = document.querySelectorAll('input[name="delete_items[]"]:checked');
    const expiryInput = document.getElementById('expiry');
    const expiryLabel = document.getElementById('expiryLabel');
    const clearExpiryButton = document.getElementById('clearExpiry');
    const categoryInput = document.getElementById('category');
    const categoryLabel = document.getElementById('categoryLabel');
    if (checked.length === 1) {
        addButton.style.display = 'none';
        editButton.disabled = false;
        expiryInput.style.display = 'inline-block';
        expiryLabel.style.display = 'inline-block';
        clearExpiryButton.style.display = 'inline-block';
        categoryInput.style.display = 'inline-block';
        categoryLabel.style.display = 'inline-block';
        const index = checked[0].value;
        const li = checked[0].closest('li');
        const text = li.querySelector('span').textContent.replace(/^=>\s*/, '').replace(/\[.*?\]\s*/, '').replace(/\(Due: [0-9\-]+\)$/, '').trim();
        const expiryValue = li.dataset.expiry || '';
        const categoryValue = li.dataset.category || '';
        editIndexInput.value = index;
        todoInput.value = text;
        expiryInput.value = expiryValue;
        categoryInput.value = categoryValue;
        todoInput.placeholder = 'Edit selected bullet';
        todoInput.focus();
    } else {
        addButton.style.display = 'inline-block';
        editButton.disabled = true;
        expiryInput.style.display = 'none';
        expiryLabel.style.display = 'none';
        clearExpiryButton.style.display = 'none';
        categoryInput.style.display = 'none';
        categoryLabel.style.display = 'none';
        editIndexInput.value = '';
        todoInput.value = '';
        expiryInput.value = '';
        categoryInput.value = '';
        todoInput.placeholder = 'Another thing to do';
    }
}

if (pinButton) {
    document.querySelectorAll('input[name="delete_items[]"]').forEach(cb => {
        cb.addEventListener('change', function() {
            updateButton();
            setActionMode();
        });
    });
    setActionMode();
}

function updateButton() {
    let hasPinned = false;
    document.querySelectorAll('input[name="delete_items[]"]:checked').forEach(cb => {
        if (cb.closest('li').hasAttribute('data-pinned')) {
            hasPinned = true;
        }
    });
    pinButton.value = hasPinned ? 'Unpin Selected' : 'Pin Selected';
}

document.querySelectorAll('button, input[type="submit"]').forEach(btn => {
    btn.addEventListener('click', function() {
        this.classList.add('pulse');
        setTimeout(() => {
            this.classList.remove('pulse');
        }, 300);
    });
});

const searchInput = document.getElementById('search');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        document.querySelectorAll('li').forEach(li => {
            const text = li.querySelector('span').textContent.toLowerCase();
            li.style.display = text.includes(query) ? '' : 'none';
        });
    });
}

const notification = document.getElementById('notification');
if (notification) {
    // Hide the message after a short delay without adding dependencies.
    setTimeout(() => notification.classList.add('hide'), 2500);
}

const todoList = document.getElementById('todoList');
let draggedItem = null;

function getDropTarget(container, y) {
    const items = [...container.querySelectorAll('li:not(.dragging)')];

    return items.reduce((closest, item) => {
        const box = item.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;

        if (offset < 0 && offset > closest.offset) {
            return { offset, item };
        }

        return closest;
    }, { offset: Number.NEGATIVE_INFINITY, item: null }).item;
}

function refreshTaskIndexes() {
    todoList.querySelectorAll('li').forEach((item, index) => {
        item.dataset.index = index;
        item.querySelector('input[type="checkbox"]').value = index;
    });
}

function saveTaskOrder() {
    const body = new URLSearchParams();
    body.append('reorder', '1');

    todoList.querySelectorAll('li').forEach((item) => {
        body.append('order[]', item.dataset.index);
    });

    fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
        credentials: 'same-origin'
    }).then(refreshTaskIndexes);
}

if (todoList) {
    todoList.addEventListener('dragstart', function(event) {
        draggedItem = event.target.closest('li');
        if (!draggedItem) return;
        draggedItem.classList.add('dragging');
        event.dataTransfer.effectAllowed = 'move';
    });

    todoList.addEventListener('dragover', function(event) {
        event.preventDefault();
        const target = getDropTarget(todoList, event.clientY);

        if (target) {
            todoList.insertBefore(draggedItem, target);
        } else {
            todoList.appendChild(draggedItem);
        }
    });

    todoList.addEventListener('drop', function(event) {
        event.preventDefault();
        if (draggedItem) {
            saveTaskOrder();
        }
    });

    todoList.addEventListener('dragend', function() {
        if (draggedItem) {
            draggedItem.classList.remove('dragging');
            draggedItem = null;
        }
    });
}
</script>

<?php endif; ?>

</body>
</html>
