using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Text;

namespace DavatShodi.GuestManager.Services;

public static class CredentialStore
{
    private const string TargetName = "DavatShodi.EGM.GuestManager";
    private const uint CredentialTypeGeneric = 1;
    private const uint CredentialPersistLocalMachine = 2;

    public static void Save(string username, string password)
    {
        if (string.IsNullOrWhiteSpace(username) || string.IsNullOrEmpty(password)) return;

        var passwordBytes = Encoding.Unicode.GetBytes(password);
        if (passwordBytes.Length > 512)
            throw new ArgumentOutOfRangeException(nameof(password), "Password is too long for Windows Credential Manager.");

        var blob = Marshal.AllocCoTaskMem(passwordBytes.Length);
        try
        {
            Marshal.Copy(passwordBytes, 0, blob, passwordBytes.Length);
            var credential = new NativeCredential
            {
                Type = CredentialTypeGeneric,
                TargetName = TargetName,
                CredentialBlobSize = (uint)passwordBytes.Length,
                CredentialBlob = blob,
                Persist = CredentialPersistLocalMachine,
                UserName = username,
                Comment = "DavatShodi EGM Guest Manager login"
            };
            if (!CredWrite(ref credential, 0))
                throw new Win32Exception(Marshal.GetLastWin32Error());
        }
        finally
        {
            for (var index = 0; index < passwordBytes.Length; index++) Marshal.WriteByte(blob, index, 0);
            Array.Clear(passwordBytes);
            Marshal.FreeCoTaskMem(blob);
        }
    }

    public static (string Username, string Password)? Load()
    {
        if (!CredRead(TargetName, CredentialTypeGeneric, 0, out var credentialPointer)) return null;
        try
        {
            var credential = Marshal.PtrToStructure<NativeCredential>(credentialPointer);
            var username = credential.UserName ?? "";
            var password = credential.CredentialBlob == IntPtr.Zero || credential.CredentialBlobSize == 0
                ? ""
                : Marshal.PtrToStringUni(credential.CredentialBlob, checked((int)credential.CredentialBlobSize / 2)) ?? "";
            return string.IsNullOrWhiteSpace(username) || string.IsNullOrEmpty(password)
                ? null
                : (username, password);
        }
        finally
        {
            CredFree(credentialPointer);
        }
    }

    public static void Delete()
    {
        if (CredDelete(TargetName, CredentialTypeGeneric, 0)) return;
        const int ErrorNotFound = 1168;
        var error = Marshal.GetLastWin32Error();
        if (error != ErrorNotFound) throw new Win32Exception(error);
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct NativeCredential
    {
        public uint Flags;
        public uint Type;
        public string? TargetName;
        public string? Comment;
        public System.Runtime.InteropServices.ComTypes.FILETIME LastWritten;
        public uint CredentialBlobSize;
        public IntPtr CredentialBlob;
        public uint Persist;
        public uint AttributeCount;
        public IntPtr Attributes;
        public string? TargetAlias;
        public string? UserName;
    }

    [DllImport("advapi32.dll", EntryPoint = "CredWriteW", CharSet = CharSet.Unicode, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CredWrite(ref NativeCredential credential, uint flags);

    [DllImport("advapi32.dll", EntryPoint = "CredReadW", CharSet = CharSet.Unicode, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CredRead(string target, uint type, uint flags, out IntPtr credential);

    [DllImport("advapi32.dll", EntryPoint = "CredDeleteW", CharSet = CharSet.Unicode, SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CredDelete(string target, uint type, uint flags);

    [DllImport("advapi32.dll")]
    private static extern void CredFree(IntPtr buffer);
}
