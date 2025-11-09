"use client";

import { useState } from "react";
import { signInWithPopup, signOut } from "firebase/auth";
import { auth, googleProvider } from "@/lib/firebase";
import { Button } from "@/components/ui/button";
import { useToast } from "@/hooks/use-toast";
import { useRouter } from "next/navigation";
import { LogIn, LogOut } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";

interface GoogleAuthButtonProps {
  onAuthSuccess?: (token: string, user: any) => void;
  onAuthError?: (error: string) => void;
  className?: string;
  variant?: "default" | "outline" | "secondary" | "ghost";
  size?: "default" | "sm" | "lg";
  isSignOut?: boolean;
  label?: string;
  useGoogleLogo?: boolean;
}

export function GoogleAuthButton({ 
  onAuthSuccess, 
  onAuthError, 
  className = "",
  variant = "default",
  size = "default",
  isSignOut = false,
  label,
  useGoogleLogo = false,
}: GoogleAuthButtonProps) {
  const [isLoading, setIsLoading] = useState(false);
  const { toast } = useToast();
  const router = useRouter();
  const { logout: contextLogout, login: contextLogin } = useAuth();

  const handleGoogleSignOut = async () => {
    try {
      setIsLoading(true);
      await signOut(auth);
      
      document.cookie = "invite_token=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
      
      try { await contextLogout(); } catch {}
      
      toast({
        title: "Signed out successfully",
        description: "You have been signed out of your account.",
      });
      
      router.push("/");
    } catch (error) {
      console.error("Sign out error:", error);
      toast({
        title: "Sign out error",
        description: "Failed to sign out. Please try again.",
        variant: "destructive",
      });
    } finally {
      setIsLoading(false);
    }
  };

  const handleGoogleSignIn = async () => {
    try {
      setIsLoading(true);

      const result = await signInWithPopup(auth, googleProvider);
      const user = result.user;

      if (!user.email) {
        throw new Error("No email provided by Google");
      }

      const response = await fetch("/api/auth/google", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          email: user.email,
          name: user.displayName || "Unknown User",
          photoURL: user.photoURL,
        }),
      });

      const data = await response.json();

      if (response.ok && data.success) {
        if (data.inviteToken) {
          document.cookie = `invite_token=${data.inviteToken}; path=/; max-age=${60 * 60 * 24 * 30}; SameSite=lax`;
        }

        toast({
          title: "Welcome back!",
          description: `Signed in successfully as ${data.user.name}`,
        });

        if (data.inviteToken) {
          try { await contextLogin(data.inviteToken); } catch {}
        }

        if (onAuthSuccess) {
          onAuthSuccess(data.inviteToken, data.user);
        } else {
          router.push("/dashboard");
        }
      } else {
        await signOut(auth);
        
        const errorMessage = data.message || "Authentication failed";
        const redirectTo = data.redirectTo || "/waitlist";

        if (response.status === 404) {
          toast({
            title: "Not on waitlist",
            description: errorMessage,
            variant: "destructive",
          });
        } else if (response.status === 403) {
          toast({
            title: "Access pending",
            description: errorMessage,
            variant: "destructive",
          });
        } else {
          toast({
            title: "Authentication failed",
            description: errorMessage,
            variant: "destructive",
          });
        }

        if (onAuthError) {
          onAuthError(data.error || "authentication_failed");
        }

        router.push(redirectTo);
      }
    } catch (error) {
      console.error("Google sign-in error:", error);
      
      try {
        await signOut(auth);
      } catch (signOutError) {
        console.error("Sign out error:", signOutError);
      }

      const errorMessage = error instanceof Error ? error.message : "Sign in failed";
      
      toast({
        title: "Sign-in failed",
        description: errorMessage,
        variant: "destructive",
      });

      if (onAuthError) {
        onAuthError(errorMessage);
      }
    } finally {
      setIsLoading(false);
    }
  };

  if (isSignOut) {
    return (
      <Button
        onClick={handleGoogleSignOut}
        disabled={isLoading}
        variant={variant}
        size={size}
        className={className}
      >
        <LogOut className="mr-2 h-4 w-4" />
        {isLoading ? "Signing out..." : "Sign out"}
      </Button>
    );
  }

  return (
    <Button
      onClick={handleGoogleSignIn}
      disabled={isLoading}
      variant={variant}
      size={size}
      className={className}
    >
      {useGoogleLogo ? (
        <img src="/google-logo.svg" alt="Google" className="mr-2 h-4 w-4" />
      ) : (
        <LogIn className="mr-2 h-4 w-4" />
      )}
      {isLoading ? "Signing in..." : (label || "Sign in with Google")}
    </Button>
  );
}
